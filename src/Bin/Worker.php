<?php

namespace App\Bin;

use App\Core\Container;
use App\Services\AiService;
use App\Services\DatabaseService;
use App\Services\LoggerService;
use App\Services\ParserService;
use App\Services\RedisService;
use Exception;

class Worker
{
    public static string $large = 'tasks_large';
    public static string $similar = 'tasks_similar';
    public static string $embedding = 'tasks_embedding';

    private bool $shouldQuit = false;

    private Container $container;
    private RedisService $redis;
    private LoggerService $log;

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->redis = $container->make('redis');
        $this->log = $container->make('logger')->withChannel('worker');
    }

    public function run(string $queueName): void
    {
        $this->log->info('started', ['queue' => $queueName]);
        echo "[*] Worker started. Queue: {$queueName}\n";

        while (!$this->shouldQuit) {
            try {
                $rawJob = $this->redis->pop($queueName, 10);
                if (!$rawJob) {
                    continue;
                }

                $data = $this->unserializeJob($rawJob);
                if (!$data) {
                    $this->log->warn('bad job payload', ['rawType' => gettype($rawJob)]);
                    continue;
                }

                $this->process($data);
            } catch (Exception $e) {
                $this->log->error('loop error: ' . $e->getMessage());
                echo "[!] Worker loop error: {$e->getMessage()}\n";
                sleep(2);
            }
        }
    }

    public function runOnce(string $queueName, int $timeoutSec = 1): bool
    {
        try {
            $rawJob = $this->redis->pop($queueName, $timeoutSec);
            if (!$rawJob) {
                return false;
            }
            $data = $this->unserializeJob($rawJob);
            if (!$data) {
                $this->log->warn('bad job payload', ['rawType' => gettype($rawJob)]);
                return false;
            }
            $this->process($data);
            return true;
        } catch (Exception $e) {
            $this->log->error('runOnce error: ' . $e->getMessage(), ['queue' => $queueName]);
            throw $e;
        }
    }

    private function unserializeJob($raw): ?array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (is_string($raw)) {
            $json = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
                return $json;
            }
        }

        try {
            $data = @unserialize($raw);
            if (is_array($data)) {
                return $data;
            }
        } catch (Exception $e) {
        }

        return null;
    }

    private function process(array $data): void
    {
        $action = (string)($data['action'] ?? '');

        if ($action === 'ping') {
            $msg = (string)($data['msg'] ?? 'pong');
            $this->log->info('ping', ['msg' => $msg]);
            echo "[worker] ping: {$msg}\n";
            return;
        }

        if ($action === 'parse_full_text') {
            $id = $data['id'] ?? null;
            $link = $data['link'] ?? null;

            if (!$id || !$link) {
                $this->log->warn('parse_full_text missing id/link', ['job' => $data]);
                echo "[worker] parse_full_text: missing id/link\n";
                return;
            }

            try {
                $this->log->info('parse_full_text start', ['id' => $id, 'link' => $link]);
                $parser = $this->container->make('parser');
                $db = $this->container->make('db');

                $result = $parser->parse((string)$link);

                $sql = "INSERT INTO news_content (news_id, content, html, meta_data)
                        VALUES (?, ?, ?, ?)
                        ON CONFLICT (news_id) DO UPDATE SET
                            content = EXCLUDED.content,
                            html = EXCLUDED.html,
                            meta_data = EXCLUDED.meta_data";
                $db->query($sql, [
                    (int)$id,
                    (string)($result['markdown'] ?? ''),
                    (string)($result['html'] ?? ''),
                    json_encode($result['meta'] ?? []),
                ]);

                $db->query("UPDATE news SET status = 2 WHERE id = ?", [(int)$id]);

                $this->log->info('parse_full_text done', ['id' => $id]);
                echo "[worker] parse_full_text done: {$id}\n";
            } catch (Exception $e) {
                $this->log->error('parse_full_text error: ' . $e->getMessage(), ['id' => $id]);
                $this->container->make('db')->query("UPDATE news SET status = 3 WHERE id = ?", [(int)$id]);
                echo "[worker] parse_full_text error: {$id} {$e->getMessage()}\n";
            }

            return;
        }

        if ($action === 'generate_summary') {
            $id = $data['id'] ?? null;
            if (!$id) {
                $this->log->warn('generate_summary missing id', ['job' => $data]);
                echo "[worker] generate_summary: missing id\n";
                return;
            }

            try {
                $this->log->info('generate_summary start', ['id' => $id]);
                $db = $this->container->make('db');
                $ai = $this->container->make('aiService');
                $lockKey = 'lock:ai:summarise';
                $lockToken = null;
                // Try to acquire quickly a few times to avoid flooding delayed queue.
                for ($i = 0; $i < 5; $i++) {
                    $lockToken = $this->redis->acquireLock($lockKey, 30);
                    if ($lockToken !== null) {
                        break;
                    }
                    usleep(300000); // 300ms
                }

                if ($lockToken === null) {
                    $job = $data;
                    $job['attempt'] = (int)($data['attempt'] ?? 0);
                    $member = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if (is_string($member)) {
                        $this->redis->zAdd('delayed:' . Worker::$large, time() + 5, $member);
                    }
                    $this->log->warn('generate_summary lock busy, delayed', ['id' => $id]);
                    return;
                }

                $row = $db->fetchOne("SELECT content FROM news_content WHERE id = ?", [(int)$id]);
                if (!is_array($row)) {
                    // fetchOne() returns false when there is no row.
                    $this->log->warn('generate_summary news_content not found', ['id' => $id]);
                    $db->query("UPDATE news_content SET status = 3 WHERE id = ?", [(int)$id]);
                    echo "[worker] generate_summary: news_content not found: {$id}\n";
                    $this->redis->releaseLock($lockKey, $lockToken);
                    return;
                }

                $text = (string)($row['content'] ?? '');
                if (trim($text) === '') {
                    $this->log->warn('generate_summary empty content', ['id' => $id]);
                    $db->query("UPDATE news_content SET status = 3 WHERE id = ?", [(int)$id]);
                    echo "[worker] generate_summary: empty content: {$id}\n";
                    $this->redis->releaseLock($lockKey, $lockToken);
                    return;
                }

                $aiData = $ai->summarise($text);

                $keywords = isset($aiData['keyWords']) ? json_encode($aiData['keyWords'], JSON_UNESCAPED_UNICODE) : null;
                $tags = isset($aiData['tags']) ? json_encode($aiData['tags'], JSON_UNESCAPED_UNICODE) : null;

                $sql = "INSERT INTO news_summary
                        (news_content_id, summary, keywords, tags, status, updated_at)
                        VALUES (?, ?, ?, ?, 0, CURRENT_TIMESTAMP)
                        ON CONFLICT (news_content_id) DO UPDATE SET
                            summary = EXCLUDED.summary,
                            keywords = EXCLUDED.keywords,
                            tags = EXCLUDED.tags,
                            updated_at = CURRENT_TIMESTAMP";
                try {
                    $db->query($sql, [
                        (int)$id,
                        (string)($aiData['summary'] ?? ''),
                        $keywords,
                        $tags,
                    ]);
                } catch (Exception $e) {
                    // Fallback when unique constraint is missing (SQLSTATE 42P10).
                    if (strpos($e->getMessage(), '42P10') !== false) {
                        $upd = $db->query(
                            "UPDATE news_summary SET summary = ?, keywords = ?, tags = ?, updated_at = CURRENT_TIMESTAMP WHERE news_content_id = ?",
                            [(string)($aiData['summary'] ?? ''), $keywords, $tags, (int)$id]
                        );
                        if ($upd->rowCount() === 0) {
                            $db->query(
                                "INSERT INTO news_summary (news_content_id, summary, keywords, tags, status, updated_at) VALUES (?, ?, ?, ?, 0, CURRENT_TIMESTAMP)",
                                [(int)$id, (string)($aiData['summary'] ?? ''), $keywords, $tags]
                            );
                        }
                    } else {
                        throw $e;
                    }
                }

                // Mark content as processed.
                $db->query("UPDATE news_content SET status = 2 WHERE id = ?", [(int)$id]);

                $this->log->info('generate_summary done', ['id' => $id]);
                echo "[worker] generate_summary done: {$id}\n";
                $this->redis->releaseLock($lockKey, $lockToken);
            } catch (Exception $e) {
                $msg = $e->getMessage();
                $this->log->error('generate_summary error: ' . $msg, ['id' => $id]);
                if (isset($lockToken)) {
                    $this->redis->releaseLock('lock:ai:summarise', $lockToken);
                }

                // If AI server is temporarily unavailable (503/429/etc), retry later via delayed queue.
                if (preg_match('/\\bhttp=(503|502|504|429)\\b/', $msg)) {
                    $attempt = (int)($data['attempt'] ?? 0) + 1;
                    $maxAttempts = 10;

                    if ($attempt <= $maxAttempts) {
                        $delay = min(900, 5 * (2 ** min(7, $attempt - 1))); // 5s..900s
                        $job = $data;
                        $job['attempt'] = $attempt;
                        $job['last_error'] = $msg;
                        $member = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                        if (is_string($member)) {
                            $zkey = 'delayed:' . Worker::$large;
                            $this->redis->zAdd($zkey, time() + $delay, $member);
                            $this->log->warn('generate_summary requeued delayed', ['id' => $id, 'attempt' => $attempt, 'delaySec' => $delay]);
                            echo "[worker] generate_summary requeued delayed: {$id} attempt={$attempt} delay={$delay}s\n";
                            return;
                        }
                    }
                }

                // If JSON was truncated / invalid, retry a few times as transient.
                if (str_contains($msg, 'Не удалось распарсить JSON') || str_contains($msg, 'AI returned non-JSON')) {
                    $attempt = (int)($data['attempt'] ?? 0) + 1;
                    if ($attempt <= 5) {
                        $delay = min(120, 5 * (2 ** ($attempt - 1)));
                        $job = $data;
                        $job['attempt'] = $attempt;
                        $job['last_error'] = $msg;
                        $member = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        if (is_string($member)) {
                            $this->redis->zAdd('delayed:' . Worker::$large, time() + $delay, $member);
                            $this->log->warn('generate_summary invalid json, requeued', ['id' => $id, 'attempt' => $attempt, 'delaySec' => $delay]);
                            return;
                        }
                    }
                }

                echo "[worker] generate_summary error: {$id} {$msg}\n";
            }

            return;
        }

        if ($action === 'generate_embedding') {
            $id = $data['id'] ?? null;
            if (!$id) {
                $this->log->warn('generate_embedding missing id', ['job' => $data]);
                return;
            }

            $db = $this->container->make('db');
            $ai = $this->container->make('aiService');

            $lockKey = 'lock:ai:embedding';
            $lockToken = $this->redis->acquireLock($lockKey, 180);
            if ($lockToken === null) {
                $member = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (is_string($member)) {
                    $this->redis->zAdd('delayed:' . Worker::$embedding, time() + 5, $member);
                }
                $this->log->warn('generate_embedding lock busy, delayed', ['id' => $id]);
                return;
            }

            try {
                $row = $db->fetchOne("SELECT content FROM news_content WHERE id = ?", [(int)$id]);
                if (!is_array($row)) {
                    $this->log->warn('generate_embedding news_content not found', ['id' => $id]);
                    return;
                }
                $text = trim((string)($row['content'] ?? ''));
                if ($text === '') {
                    $this->log->warn('generate_embedding empty content', ['id' => $id]);
                    return;
                }

                $vec = $ai->embedding($text);
                if (!is_array($vec) || !$vec) {
                    throw new Exception('Embedding returned empty vector');
                }

                // Insert/update embedding.
                $vecLiteral = '[' . implode(',', array_map(static fn($v) => is_numeric($v) ? (string)$v : '0', $vec)) . ']';
                $sql = "INSERT INTO news_content_embedding (news_content_id, embedding, status, updated_at)
                        VALUES (?, ?::vector, 2, CURRENT_TIMESTAMP)
                        ON CONFLICT (news_content_id) DO UPDATE SET
                            embedding = EXCLUDED.embedding,
                            status = 2,
                            updated_at = CURRENT_TIMESTAMP";
                $db->query($sql, [(int)$id, $vecLiteral]);

                $this->log->info('generate_embedding done', ['id' => $id]);
            } catch (Exception $e) {
                $msg = $e->getMessage();
                $this->log->error('generate_embedding error: ' . $msg, ['id' => $id]);

                if (preg_match('/\\bhttp=(503|502|504|429)\\b/', $msg)) {
                    $attempt = (int)($data['attempt'] ?? 0) + 1;
                    if ($attempt <= 10) {
                        $delay = min(900, 5 * (2 ** min(7, $attempt - 1)));
                        $job = $data;
                        $job['attempt'] = $attempt;
                        $job['last_error'] = $msg;
                        $member = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        if (is_string($member)) {
                            $this->redis->zAdd('delayed:' . Worker::$embedding, time() + $delay, $member);
                            $this->log->warn('generate_embedding requeued delayed', ['id' => $id, 'attempt' => $attempt, 'delaySec' => $delay]);
                        }
                    }
                }
            } finally {
                $this->redis->releaseLock($lockKey, $lockToken);
            }

            return;
        }

        $this->log->warn('unknown action', ['action' => $action, 'job' => $data]);
    }
}
