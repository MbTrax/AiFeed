<?php

namespace App\Commands;

use App\Core\Command;
use Exception;

class RssParseCommand extends Command
{
    public function execute(array $args): void
    {
        $url = $args[0] ?? null;

        if (!$url) {
            echo "\e[31m[Ошибка]\e[0m Укажите URL RSS-канала.\n";
            return;
        }

        echo "[*] Начинаю парсинг: $url...\n";

        try {
            $rssService = $this->container->make('rss');
            $db = $this->container->make('db');

            $items = $rssService->fetch($url);
            $count = 0;

            foreach ($items as $item) {
                $sql = "INSERT INTO news (title, link, description, created_at) 
                        VALUES (?, ?, ?, ?) 
                        ON CONFLICT (link) DO NOTHING";

                $stmt = $db->query($sql, [
                    $item['title'],
                    $item['link'],
                    $item['description'],
                    date('Y-m-d H:i:s')
                ]);

                if ($stmt->rowCount() > 0) {
                    $count++;
                }
            }

            echo "\e[32m[Успех]\e[0m Обработка завершена. Реально добавлено: $count\n";

        } catch (Exception $e) {
            echo "\e[31m[Критическая ошибка]\e[0m " . $e->getMessage() . "\n";
        }
    }
}