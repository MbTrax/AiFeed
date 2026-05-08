# AiFeed
Модуль для сбора новостей из RSS и дальнейшей обработки (парсинг полного текста и саммари) через очереди Redis + воркеры.

## Быстрый старт
1. Установить зависимости: `composer install`
2. Создать `.env` (можно скопировать из `.env.example`) и заполнить:
   - `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`
   - `REDIS_HOST`, `REDIS_PORT`, `REDIS_PREFIX`
   - `RSS_URLS`, `RSS_POLL_INTERVAL`
   - `LOG_FILE`, `PID_FILE`
   - `AI_HOST`, `AI_SUMMARISE_MODEL`, `AI_EMBEDDING_MODEL`
3. Применить миграции БД (Phinx): `vendor/bin/phinx migrate -e development`

## Команды
Все команды запускаются через консольный вход `src/Bin/Console.php`:

`php src/Bin/Console.php`

### `rss:parse`
Парсит один RSS-канал и добавляет новые записи в таблицу `news`.

Пример:
`php src/Bin/Console.php rss:parse "https://example.com/rss.xml"`

### `composer:start`
«Композитор» — процесс, который:
1. Постоянно поллит RSS-источники из `RSS_URLS` и добавляет новые записи в `news`.
2. Берет новые записи из `news` со `status = 0` и кладет задачи парсинга полного текста в очередь `tasks_similar`.
3. Берет новые записи из `news_content` со `status = 0` и кладет задачи генерации саммари в очередь `tasks_large`.

Запуск:
`php src/Bin/Console.php composer:start`

### `worker:start`
Запускает воркер на указанной очереди Redis.

Формат:
`php src/Bin/Console.php worker:start <queue>`

Обычно нужно 2 воркера (в разных терминалах):
- парсинг полного текста: `php src/Bin/Console.php worker:start tasks_similar`
- генерация саммари: `php src/Bin/Console.php worker:start tasks_large`

### `app:start`
Запускает все приложение одной командой (простой supervisor):
- `composer:start`
- `worker:start tasks_similar`
- `worker:start tasks_large`

Запуск:
`php src/Bin/Console.php app:start`

### `app:stop`
Останавливает процессы, поднятые `app:start` (через pid-file `PID_FILE`).

Запуск:
`php src/Bin/Console.php app:stop`

### `test:services`
Проверка доступности сервисов (Redis/DB/RSS).

`php src/Bin/Console.php test:services`

### `test:worker`
Тест воркера: кладет `ping`-job в очередь и обрабатывает одну задачу.

`php src/Bin/Console.php test:worker tasks_similar`

### `test:composer`
Тест композитора: выполняет один цикл (`runOnce`) без бесконечного лупа.

`php src/Bin/Console.php test:composer`

Чек-лист:
1. `composer install`
2. `.env` заполнен (DB/REDIS/RSS/AI)
3. БД доступна, миграции применены: `vendor/bin/phinx migrate -e development`
4. Redis доступен
5. Запуск: `php src/Bin/Console.php app:start`
