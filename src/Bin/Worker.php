<?php

namespace App\Bin;

use Exception;

class Worker
{
    private bool $shouldQuit = false;

    public static string $large = 'tasks_large';
    public static string $similar = 'tasks_similar';

    public function __construct($container) {
        $this->redis = $container->make('redis');
        $this->db = $container->make('db');
        $this->rss = $container->make('rss');
        $this->parser = $container->make('parser');
        $this->aiService = $container->make('aiService');
    }

    public function run(string $queueName): void
    {
        echo "[*] Воркер запущен. Очередь: {$queueName}\n";

        while (!$this->shouldQuit) {
            try {
                $rawJob = $this->redis->pop($queueName, 10);
                if ($rawJob) {
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
        if (is_array($raw)) return $raw;
        $json = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) return $json;
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

        }

        if ($action === 'parse_full_text') {
            $id = $data['id'] ?? null;
            $link = $data['link'] ?? null;

            if (!$id || !$link) {
                echo "\e[Worker][31m[!] Ошибка:\e[0m ID или Link отсутствуют в задаче.\n";
                return;
            }

            try {
                echo "[Worker] [parse_full_text] ID $id: $link\n";
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
            $id = $data['id'] ?? null;

            if (!$id) {
                echo "\e[31m[!] Ошибка:\e[0m Нет данных для суммаризации.\n";
                return;
            }

            try {
                echo "[Worker] [generate_summary] ID $id...\n";
                $content = $this->db->fetchOne("SELECT id FROM news_content WHERE id = ?", [$id]);
                $aiData = $this->aiService->summarise($content);
                $sql = "INSERT INTO news_summary 
                (news_content_id, summary, keywords, tags, status, updated_at) 
                VALUES (?, ?, ?, ?, 0)
                ON CONFLICT (news_content_id) DO UPDATE SET 
                    summary = EXCLUDED.summary,
                    keywords = EXCLUDED.keywords,
                    tags = EXCLUDED.tags,";

                $keywords = isset($aiData['keyWords']) ? json_encode($aiData['keyWords'], JSON_UNESCAPED_UNICODE) : null;
                $tags = isset($aiData['tags']) ? json_encode($aiData['tags'], JSON_UNESCAPED_UNICODE) : null;

                $this->db->query($sql, [
                    $id,
                    $aiData['summary'] ?? '',
                    $keywords,
                    $tags
                ]);

                echo "\e[32m[Worker OK]\e[0m Саммари для контента $id готово.\n";

            } catch (Exception $e) {
                echo "\e[31m[Worker Error]\e[0m ID $id: " . $e->getMessage() . "\n";
            }
        }
    }
}