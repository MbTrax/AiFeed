<?php

namespace App\Bin;

use App\Services\RedisService;
use App\Services\DatabaseService;
use App\Services\RssService;
use App\Services\ParserService;
use Exception;

class Worker
{
    private RedisService $redis;
    private DatabaseService $db;
    private RssService $rss;
    private ParserService $parser;
    private bool $shouldQuit = false;

    public function __construct(
        RedisService $redis,
        DatabaseService $db,
        RssService $rss,
        ParserService $parser
    ) {
        $this->redis = $redis;
        $this->db = $db;
        $this->rss = $rss;
        $this->parser = $parser;
    }

    public function run(string $queueName): void
    {
        echo "[*] Воркер запущен. Очередь: {$queueName}\n";

        while (!$this->shouldQuit) {
            try {
                $rawJob = $this->redis->pop($queueName, 10);

                if ($rawJob) {
                    // ГИБКАЯ ДЕКОДИРОВКА: проверяем, JSON это или PHP Serialize
                    $data = $this->unserializeJob($rawJob);

                    if ($data) {
                        $this->process($data);
                    }
                }
            } catch (Exception $e) {
                echo "\e[31m[!] Ошибка цикла воркера:\e[0m " . $e->getMessage() . "\n";
                sleep(2);
            }
        }
    }

    private function unserializeJob($raw)
    {
        // Если это уже массив (некоторые либы делают это сами)
        if (is_array($raw)) return $raw;

        // Попытка JSON (лучший вариант)
        $json = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) return $json;

        // Попытка PHP Serialize (твой текущий случай)
        try {
            $data = @unserialize($raw);
            if ($data !== false) return $data;
        } catch (Exception $e) {}

        return null;
    }

    private function process(array $data): void
    {
        $action = $data['action'] ?? '';

        if ($action === 'fetch_rss') {
            // ... (твой код RSS остается без изменений) ...
        }

        if ($action === 'parse_full_text') {
            // ИСПРАВЛЕНИЕ: используем $data вместо $job
            $id = $data['id'] ?? null;
            $link = $data['link'] ?? null;

            if (!$id || !$link) {
                echo "\e[Worker][31m[!] Ошибка:\e[0m ID или Link отсутствуют в задаче.\n";
                return;
            }

            try {
                echo "[Worker] Глубокий парсинг ID $id: $link\n";

                // ИСПРАВЛЕНИЕ: передаем $link в парсер
                $result = $this->parser->parse($link);

                $sql = "INSERT INTO news_content (news_id, content, html, meta_data) 
                        VALUES (?, ?, ?, ?) 
                        ON CONFLICT (news_id) DO UPDATE SET 
                            content = EXCLUDED.content, 
                            html = EXCLUDED.html, 
                            meta_data = EXCLUDED.meta_data";
                $this->db->query($sql, [
                    $id,
                    $result['markdown'],
                    $result['html'],
                    json_encode($result['meta'] ?? [])
                ]);

                $this->db->query("UPDATE news SET status = 2 WHERE id = ?", [$id]);

                echo "\e[32m[Worker OK]\e[0m Контент для ID $id сохранен.\n";

            } catch (Exception $e) {
                echo "\e[31m[Worker Error]\e[0m ID $id: " . $e->getMessage() . "\n";
                $this->db->query("UPDATE news SET status = 3 WHERE id = ?", [$id]);
            }
        }

        if ($action === 'generate_summary') {
            $news_content_id = $data['id'] ?? null; // ID из таблицы news_content
            $text = $data['full_text'] ?? null;

            if (!$news_content_id || !$text) {
                echo "\e[31m[!] Ошибка:\e[0m Нет данных для суммаризации.\n";
                return;
            }

            try {
                echo "[Worker] Обработка контента ID $news_content_id...\n";

                // Настройка запроса к LM Studio
                // Если воркер в Docker, используйте http://host.docker.internal:1234/v1/...
                $apiUrl = 'http://localhost:1234/v1/chat/completions';

                $jsonSchema = [
                    "type" => "object",
                    "strict" => true,
                    "properties" => [
                        "tags" => ["type" => "array", "items" => ["type" => "string"]],
                        "keyWords" => ["type" => "array", "items" => ["type" => "string"]],
                        "summary" => ["type" => "string"]
                    ],
                    "required" => ["tags", "keyWords", "summary"]
                ];

                $payload = [
                    'model' => 'meta-llama-3.1-8b-instruct',
                    [
                        'role' => 'system',
                        'content' => "Ты аналитик новостей. Проанализируй текст и верни ответ строго в формате JSON согласно схеме."
                    ],
                    'messages' => [
                        ['role' => 'user', 'content' => $text]
                    ],
                    'response_format' => [
                        'type' => 'json_schema',
                        'json_schema' => [
                            'name' => "characters",
                            'schema' => $jsonSchema,
                        ]
                    ],
                    'temperature' => 0.1
                ];

                $ch = curl_init($apiUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_TIMEOUT, 1000);

                $response = curl_exec($ch);
                $result = json_decode($response, true);

                // Получаем строку с JSON из ответа нейросети
                $aiRawJson = $result['choices'][0]['message']['content'] ?? null;
                $aiData = json_decode($aiRawJson, true);

                if (!$aiData) {
                    throw new Exception("Не удалось распарсить JSON от нейросети.");
                }

                $sql = "INSERT INTO news_summary 
                (news_content_id, summary, keywords, tags, status, updated_at) 
                VALUES (?, ?, ?, ?, 0)
                ON CONFLICT (news_content_id) DO UPDATE SET 
                    summary = EXCLUDED.summary,
                    keywords = EXCLUDED.keywords,
                    tags = EXCLUDED.tags,";

                // Подготавливаем данные (убеждаемся, что ключи из AI соответствуют вашим переменным)
                $keywords = isset($aiData['keyWords']) ? json_encode($aiData['keyWords'], JSON_UNESCAPED_UNICODE) : null;
                $tags = isset($aiData['tags']) ? json_encode($aiData['tags'], JSON_UNESCAPED_UNICODE) : null;

                $this->db->query($sql, [
                    $news_content_id,
                    $aiData['summary'] ?? '',
                    $keywords,
                    $tags
                ]);

                echo "\e[32m[Worker OK]\e[0m Саммари для контента $news_content_id готово.\n";

            } catch (Exception $e) {
                echo "\e[31m[Worker Error]\e[0m ID $news_content_id: " . $e->getMessage() . "\n";
            }
        }
    }
}