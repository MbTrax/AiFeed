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
            echo "[rss:parse] missing url\n";
            return;
        }

        $logger = $this->container->make('logger')->withChannel('rss');
        $logger->info('parse start', ['url' => $url]);

        echo "[rss:parse] parsing {$url}\n";

        try {
            $rssService = $this->container->make('rss');
            $db = $this->container->make('db');

            $items = $rssService->fetch($url);
            $count = 0;

            foreach ($items as $item) {
                $sql = "INSERT INTO news (title, link, description, created_at)
                        VALUES (?, ?, ?, CURRENT_TIMESTAMP)
                        ON CONFLICT (link) DO NOTHING";

                $stmt = $db->query($sql, [
                    (string)($item['title'] ?? ''),
                    (string)($item['link'] ?? ''),
                    (string)($item['description'] ?? ''),
                ]);

                if ($stmt->rowCount() > 0) {
                    $count++;
                }
            }

            $logger->info('parse done', ['url' => $url, 'added' => $count]);
            echo "[rss:parse] done, added={$count}\n";
        } catch (Exception $e) {
            $logger->error($e->getMessage(), ['url' => $url]);
            echo "[rss:parse] error: " . $e->getMessage() . "\n";
        }
    }
}

