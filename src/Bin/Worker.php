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
                echo "\e[31m[!] Ошибка:\e[0m ID или Link отсутствуют в задаче.\n";
                return;
            }

            try {
                echo "[Worker] Глубокий парсинг ID $id: $link\n";

                // ИСПРАВЛЕНИЕ: передаем $link в парсер
                $result = $this->parser->parse($link);

                $sql = "INSERT INTO news_content (news_id, full_text, html_content, meta_data) 
                        VALUES (?, ?, ?, ?) 
                        ON CONFLICT (news_id) DO UPDATE SET 
                            full_text = EXCLUDED.full_text, 
                            html_content = EXCLUDED.html_content, 
                            meta_data = EXCLUDED.meta_data,
                            parsed_at = NOW()";

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
    }
}