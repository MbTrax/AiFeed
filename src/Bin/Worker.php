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

    private const LOCK_SUMMARISE = 'lock:ai:summarise';
    private const LOCK_EMBEDDING = 'lock:ai:embedding';

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

        try {
            switch ($action) {
                case 'parse_full_text':
                    $this->handleParseFullText($data);
                    return;
                case 'generate_summary':
                    $this->handleGenerateSummary($data);
                    return;
                case 'generate_enrichment':
                    $this->handleGenerateEnrichment($data);
                    return;
                case 'generate_embedding':
                    $this->handleGenerateEmbedding($data);
                    return;
                default:
                    $this->log->warn('unknown action', ['action' => $action, 'job' => $data]);
                    return;
            }
        } catch (Exception $e) {
            // Handlers are defensive but this guarantees the worker loop won't crash.
            $this->log->error('handler exception: ' . $e->getMessage(), ['action' => $action, 'job' => $data]);
            echo "[worker] {$action} exception msg={$e->getMessage()}\n";
        }
    }

    private function handleParseFullText(array $data): void
    {
        $id = $data['id'] ?? null;
        $link = $data['link'] ?? null;

        if (!$id || !$link) {
            $this->log->warn('parse_full_text missing id/link', ['job' => $data]);
            echo "[worker] parse_full_text missing id/link\n";
            return;
        }

        try {
            $this->log->info('parse_full_text start', ['id' => (int)$id, 'link' => (string)$link]);
            /** @var ParserService $parser */
            $parser = $this->container->make('parser');
            /** @var DatabaseService $db */
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

            $this->log->info('parse_full_text done', ['id' => (int)$id]);
            echo "[worker] parse_full_text done id={$id}\n";
        } catch (Exception $e) {
            $this->log->error('parse_full_text error: ' . $e->getMessage(), ['id' => (int)$id]);
            $this->container->make('db')->query("UPDATE news SET status = 3 WHERE id = ?", [(int)$id]);
            echo "[worker] parse_full_text error id={$id} msg={$e->getMessage()}\n";
        }
    }

    private function handleGenerateSummary(array $data): void
    {
        $id = $data['id'] ?? null;
        if (!$id) {
            $this->log->warn('generate_summary missing id', ['job' => $data]);
            echo "[worker] generate_summary missing id\n";
            return;
        }

        /** @var DatabaseService $db */
        $db = $this->container->make('db');
        /** @var AiService $ai */
        $ai = $this->container->make('aiService');

        $lockToken = $this->acquireLockWithRetries(self::LOCK_SUMMARISE, 30, 5, 300000);
        if ($lockToken === null) {
            $this->requeueDelayed(Worker::$large, $data, 5, 'summarise lock busy');
            return;
        }

        try {
            $row = $db->fetchOne("SELECT content FROM news_content WHERE id = ?", [(int)$id]);
            if (!is_array($row)) {
                $this->log->warn('generate_summary news_content not found', ['id' => (int)$id]);
                $db->query("UPDATE news_content SET status = 3 WHERE id = ?", [(int)$id]);
                echo "[worker] generate_summary news_content not found id={$id}\n";
                return;
            }

            $text = (string)($row['content'] ?? '');
            if (trim($text) === '') {
                $this->log->warn('generate_summary empty content', ['id' => (int)$id]);
                $db->query("UPDATE news_content SET status = 3 WHERE id = ?", [(int)$id]);
                echo "[worker] generate_summary empty content id={$id}\n";
                return;
            }

            $this->log->info('generate_summary start', ['id' => (int)$id]);
            $aiData = $ai->summarise($text);
            $this->upsertSummary($db, (int)$id, $aiData);
            $db->query("UPDATE news_content SET status = 2 WHERE id = ?", [(int)$id]);

            $this->log->info('generate_summary done', ['id' => (int)$id]);
            echo "[worker] generate_summary done id={$id}\n";

            // Compatibility bridge: old jobs only created summaries. Trigger embedding via enrichment.
            $this->redis->push(Worker::$large, [
                'action' => 'generate_enrichment',
                'id' => (int)$id,
                'do_summary' => false,
                'do_embedding' => true,
            ]);
        } catch (Exception $e) {
            $msg = $e->getMessage();
            $this->log->error('generate_summary error: ' . $msg, ['id' => (int)$id]);
            echo "[worker] generate_summary error id={$id} msg={$msg}\n";

            $this->handleAiRetry(Worker::$large, $data, $msg, false);
        } finally {
            $this->redis->releaseLock(self::LOCK_SUMMARISE, $lockToken);
        }
    }

    private function handleGenerateEnrichment(array $data): void
    {
        $id = $data['id'] ?? null;
        if (!$id) {
            $this->log->warn('generate_enrichment missing id', ['job' => $data]);
            echo "[worker] generate_enrichment missing id\n";
            return;
        }

        /** @var DatabaseService $db */
        $db = $this->container->make('db');
        /** @var AiService $ai */
        $ai = $this->container->make('aiService');

        $doSummary = array_key_exists('do_summary', $data) ? (bool)$data['do_summary'] : true;
        $doEmbedding = array_key_exists('do_embedding', $data) ? (bool)$data['do_embedding'] : true;

        $this->log->info('generate_enrichment start', ['id' => (int)$id, 'doSummary' => $doSummary, 'doEmbedding' => $doEmbedding]);

        $row = $db->fetchOne("SELECT content FROM news_content WHERE id = ?", [(int)$id]);
        if (!is_array($row)) {
            $this->log->warn('generate_enrichment news_content not found', ['id' => (int)$id]);
            $db->query("UPDATE news_content SET status = 3 WHERE id = ?", [(int)$id]);
            echo "[worker] generate_enrichment news_content not found id={$id}\n";
            return;
        }

        $contentText = trim((string)($row['content'] ?? ''));
        if ($contentText === '') {
            $this->log->warn('generate_enrichment empty content', ['id' => (int)$id]);
            $db->query("UPDATE news_content SET status = 3 WHERE id = ?", [(int)$id]);
            echo "[worker] generate_enrichment empty content id={$id}\n";
            return;
        }

        try {
            if ($doSummary) {
                $lockToken = $this->acquireLockWithRetries(self::LOCK_SUMMARISE, 30, 5, 300000);
                if ($lockToken === null) {
                    $this->requeueDelayed(Worker::$large, $data, 5, 'summarise lock busy');
                    return;
                }

                try {
                    $aiData = $ai->summarise($contentText);
                    $this->upsertSummary($db, (int)$id, $aiData);
                    $db->query("UPDATE news_content SET status = 2 WHERE id = ?", [(int)$id]);
                } finally {
                    $this->redis->releaseLock(self::LOCK_SUMMARISE, $lockToken);
                }
            }

            if ($doEmbedding) {
                $summaryText = $this->loadSummaryText($db, (int)$id);
                if ($summaryText === '') {
                    if (!$doSummary) {
                        $job = $data;
                        $job['do_summary'] = true;
                        $job['do_embedding'] = true;
                        $this->requeueDelayed(Worker::$large, $job, 5, 'missing summary');
                        return;
                    }
                    $this->log->warn('generate_enrichment missing summary', ['id' => (int)$id]);
                    echo "[worker] generate_enrichment missing summary id={$id}\n";
                    return;
                }

                $lockToken = $this->redis->acquireLock(self::LOCK_EMBEDDING, 30);
                if ($lockToken === null) {
                    $this->requeueDelayed(Worker::$large, $data, 5, 'embedding lock busy');
                    return;
                }

                try {
                    $vec = $ai->embedding($summaryText);
                    if (!is_array($vec) || !$vec) {
                        throw new Exception('Embedding returned empty vector');
                    }
                    $this->upsertEmbedding($db, (int)$id, $vec);
                } finally {
                    $this->redis->releaseLock(self::LOCK_EMBEDDING, $lockToken);
                }
            }

            $this->log->info('generate_enrichment done', ['id' => (int)$id]);
            echo "[worker] generate_enrichment done id={$id}\n";
        } catch (Exception $e) {
            $msg = $e->getMessage();
            $this->log->error('generate_enrichment error: ' . $msg, ['id' => (int)$id]);
            echo "[worker] generate_enrichment error id={$id} msg={$msg}\n";

            $this->handleAiRetry(Worker::$large, $data, $msg, true);
        }
    }

    private function handleGenerateEmbedding(array $data): void
    {
        $id = $data['id'] ?? null;
        if (!$id) {
            $this->log->warn('generate_embedding missing id', ['job' => $data]);
            echo "[worker] generate_embedding missing id\n";
            return;
        }

        /** @var DatabaseService $db */
        $db = $this->container->make('db');
        /** @var AiService $ai */
        $ai = $this->container->make('aiService');

        $summaryText = $this->loadSummaryText($db, (int)$id);
        if ($summaryText === '') {
            $this->log->warn('generate_embedding missing summary', ['id' => (int)$id]);
            echo "[worker] generate_embedding missing summary id={$id}\n";
            return;
        }

        $lockToken = $this->redis->acquireLock(self::LOCK_EMBEDDING, 30);
        if ($lockToken === null) {
            $this->requeueDelayed(Worker::$embedding, $data, 5, 'embedding lock busy');
            return;
        }

        try {
            $vec = $ai->embedding($summaryText);
            if (!is_array($vec) || !$vec) {
                throw new Exception('Embedding returned empty vector');
            }

            $this->upsertEmbedding($db, (int)$id, $vec);
            $this->log->info('generate_embedding done', ['id' => (int)$id]);
            echo "[worker] generate_embedding done id={$id}\n";
        } catch (Exception $e) {
            $msg = $e->getMessage();
            $this->log->error('generate_embedding error: ' . $msg, ['id' => (int)$id]);
            echo "[worker] generate_embedding error id={$id} msg={$msg}\n";

            $this->handleAiRetry(Worker::$embedding, $data, $msg, false);
        } finally {
            $this->redis->releaseLock(self::LOCK_EMBEDDING, $lockToken);
        }
    }

    private function acquireLockWithRetries(string $key, int $ttlSec, int $tries, int $sleepUs): ?string
    {
        for ($i = 0; $i < $tries; $i++) {
            $token = $this->redis->acquireLock($key, $ttlSec);
            if ($token !== null) {
                return $token;
            }
            if ($i < $tries - 1) {
                usleep($sleepUs);
            }
        }
        return null;
    }

    private function handleAiRetry(string $queue, array $job, string $msg, bool $embeddingOnly): void
    {
        if ($this->isTransientAiError($msg)) {
            $attempt = (int)($job['attempt'] ?? 0) + 1;
            if ($attempt <= 10) {
                $j = $job;
                $j['attempt'] = $attempt;
                $j['last_error'] = $msg;
                if ($embeddingOnly) {
                    $j['do_summary'] = false;
                    $j['do_embedding'] = true;
                }
                $delay = $this->computeBackoffDelaySec($attempt, 5, 900);
                $this->requeueDelayed($queue, $j, $delay, 'transient ai error');
                return;
            }
        }

        if ($this->isAiJsonError($msg)) {
            $attempt = (int)($job['attempt'] ?? 0) + 1;
            if ($attempt <= 5) {
                $j = $job;
                $j['attempt'] = $attempt;
                $j['last_error'] = $msg;
                $delay = $this->computeBackoffDelaySec($attempt, 5, 120);
                $this->requeueDelayed($queue, $j, $delay, 'invalid ai json');
            }
        }
    }

    private function requeueDelayed(string $queue, array $job, int $delaySec, string $reason): void
    {
        $delaySec = max(1, $delaySec);
        $member = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($member)) {
            $this->log->warn('requeue failed: json_encode', ['queue' => $queue, 'reason' => $reason]);
            return;
        }

        $this->redis->zAdd('delayed:' . $queue, time() + $delaySec, $member);
        $this->log->warn('requeued delayed', [
            'queue' => $queue,
            'delaySec' => $delaySec,
            'reason' => $reason,
            'action' => $job['action'] ?? null,
            'id' => $job['id'] ?? null,
            'attempt' => $job['attempt'] ?? null,
        ]);
    }

    private function computeBackoffDelaySec(int $attempt, int $baseSec, int $maxSec): int
    {
        $exp = 2 ** max(0, $attempt - 1);
        $d = $baseSec * $exp;
        if ($d > $maxSec) {
            $d = $maxSec;
        }
        return (int)$d;
    }

    private function isTransientAiError(string $msg): bool
    {
        return (bool)preg_match('/\\bhttp=(503|502|504|429)\\b/', $msg);
    }

    private function isAiJsonError(string $msg): bool
    {
        // Handle both correct UTF-8 and mojibake variants.
        if (str_contains($msg, 'AI returned non-JSON')) return true;
        if (str_contains($msg, 'распарс')) return true; // "распарсить JSON" etc
        if (str_contains($msg, 'РќРµ') && str_contains($msg, 'JSON')) return true;
        return false;
    }

    private function upsertSummary(DatabaseService $db, int $newsContentId, array $aiData): void
    {
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
                (int)$newsContentId,
                (string)($aiData['summary'] ?? ''),
                $keywords,
                $tags,
            ]);
        } catch (Exception $e) {
            // Fallback when unique constraint is missing (SQLSTATE 42P10).
            if (strpos($e->getMessage(), '42P10') !== false) {
                $upd = $db->query(
                    "UPDATE news_summary SET summary = ?, keywords = ?, tags = ?, updated_at = CURRENT_TIMESTAMP WHERE news_content_id = ?",
                    [(string)($aiData['summary'] ?? ''), $keywords, $tags, (int)$newsContentId]
                );
                if ($upd->rowCount() === 0) {
                    $db->query(
                        "INSERT INTO news_summary (news_content_id, summary, keywords, tags, status, updated_at) VALUES (?, ?, ?, ?, 0, CURRENT_TIMESTAMP)",
                        [(int)$newsContentId, (string)($aiData['summary'] ?? ''), $keywords, $tags]
                    );
                }
            } else {
                throw $e;
            }
        }
    }

    private function upsertEmbedding(DatabaseService $db, int $newsContentId, array $vec): void
    {
        $vecLiteral = '[' . implode(',', array_map(static fn($v) => is_numeric($v) ? (string)$v : '0', $vec)) . ']';
        $sql = "INSERT INTO news_content_embedding (news_content_id, embedding, status, updated_at)
                VALUES (?, ?::vector, 2, CURRENT_TIMESTAMP)
                ON CONFLICT (news_content_id) DO UPDATE SET
                    embedding = EXCLUDED.embedding,
                    status = 2,
                    updated_at = CURRENT_TIMESTAMP";
        $db->query($sql, [(int)$newsContentId, $vecLiteral]);
    }

    private function loadSummaryText(DatabaseService $db, int $newsContentId): string
    {
        $row = $db->fetchOne(
            "SELECT summary
             FROM news_summary
             WHERE news_content_id = ?
             LIMIT 1",
            [$newsContentId]
        );
        if (!is_array($row)) {
            return '';
        }
        return trim((string)($row['summary'] ?? ''));
    }
}
