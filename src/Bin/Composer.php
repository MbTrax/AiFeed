<?php
namespace App\Bin;

use App\Core\Container;
use App\Services\DatabaseService;
use App\Services\LoggerService;
use App\Services\RedisService;
use App\Services\RssService;

class Composer
{
    private RedisService $redis;
    private DatabaseService $db;
    private RssService $rss;
    private array $config;
    private LoggerService $log;

    public function __construct(Container $container)
    {
        $this->redis = $container->make('redis');
        $this->db = $container->make('db');
        $this->rss = $container->make('rss');
        $this->config = (array)$container->make('config');
        $this->log = $container->make('logger')->withChannel('composer');
    }

    public function run(): void
    {
        $this->log->info('started');
        echo "[Composer] Started.\n";

        while (true) {
            $this->runOnce();
            sleep(5);
        }
    }

    public function runOnce(): void
    {
        $this->drainDelayed();
        $this->pollRssSources();

        // Atomically "claim" rows for processing to avoid races between multiple composers.
        $news = $this->db->fetchAll(<<<'SQL'
UPDATE news n
SET status = 1
WHERE n.id IN (
    SELECT id
    FROM news
    WHERE status = 0
    ORDER BY id
    LIMIT 10
    FOR UPDATE SKIP LOCKED
)
RETURNING n.id, n.link
SQL);

        foreach ($news as $item) {
            $this->redis->push(Worker::$similar, [
                'action' => 'parse_full_text',
                'id' => (int)$item['id'],
                'link' => (string)$item['link'],
            ]);
            $this->log->info('queued parse_full_text', ['id' => (int)$item['id']]);
            echo "[Composer] queued parse_full_text: {$item['id']}\n";
        }

        $content = $this->db->fetchAll(<<<'SQL'
UPDATE news_content c
SET status = 1
WHERE c.id IN (
    SELECT id
    FROM news_content
    WHERE status = 0
    ORDER BY id
    LIMIT 10
    FOR UPDATE SKIP LOCKED
)
RETURNING c.id
SQL);

        foreach ($content as $item) {
            $this->redis->push(Worker::$large, [
                'action' => 'generate_enrichment',
                'id' => (int)$item['id'],
            ]);
            $this->log->info('queued generate_enrichment', ['id' => (int)$item['id']]);
            echo "[Composer] queued generate_enrichment: {$item['id']}\n";
        }
    }

    private function drainDelayed(): void
    {
        $now = time();

        foreach ([Worker::$similar, Worker::$large] as $queue) {
            $zkey = "delayed:{$queue}";
            $members = $this->redis->zRangeByScore($zkey, 0, $now, 200);
            if (!$members) {
                continue;
            }

            foreach ($members as $member) {
                $this->redis->zRem($zkey, $member);
                $data = json_decode($member, true);
                if (!is_array($data)) {
                    continue;
                }
                $this->redis->push($queue, $data);
            }

            $this->log->info('drained delayed', ['queue' => $queue, 'count' => count($members)]);
        }
    }

    private function pollRssSources(): void
    {
        $urls = $this->config['rss']['urls'] ?? [];
        if (!is_array($urls) || !$urls) {
            return;
        }

        $interval = (int)($this->config['rss']['pollIntervalSec'] ?? 300);
        if ($interval < 10) {
            $interval = 10;
        }

        $key = 'aifeed:last_rss_poll_ts';
        $now = time();
        $last = (int)($this->redis->get($key) ?: 0);

        if ($last && ($now - $last) < $interval) {
            return;
        }

        $this->redis->set($key, (string)$now, $interval + 60);

        foreach ($urls as $url) {
            $url = trim((string)$url);
            if ($url === '') {
                continue;
            }

            try {
                $items = $this->rss->fetch($url);
                $added = 0;

                foreach ($items as $item) {
                    $link = (string)($item['link'] ?? '');
                    if ($link === '') {
                        continue;
                    }

                    $sql = "INSERT INTO news (title, link, description, created_at)
                            VALUES (?, ?, ?, CURRENT_TIMESTAMP)
                            ON CONFLICT (link) DO NOTHING";

                    $stmt = $this->db->query($sql, [
                        (string)($item['title'] ?? ''),
                        $link,
                        (string)($item['description'] ?? ''),
                    ]);

                    if ($stmt->rowCount() > 0) {
                        $added++;
                    }
                }

                if ($added > 0) {
                    $this->log->info('rss added', ['url' => $url, 'added' => $added]);
                    echo "[Composer] RSS {$url}: +{$added}\n";
                }
            } catch (\Throwable $e) {
                $this->log->error('rss error: ' . $e->getMessage(), ['url' => $url]);
                echo "[Composer] RSS error {$url}: {$e->getMessage()}\n";
            }
        }
    }
}
