<?php

namespace App\Commands;

use App\Core\Command;
use App\Bin\Worker;

final class BackfillEmbeddingsCommand extends Command
{
    public function execute(array $args): void
    {
        $limit = (int)($args[0] ?? 200);
        if ($limit < 1) $limit = 200;
        if ($limit > 5000) $limit = 5000;

        $db = $this->container->make('db');
        $redis = $this->container->make('redis');
        $log = $this->container->make('logger')->withChannel('backfill');

        $rows = $db->fetchAll(
            "SELECT c.id
             FROM news_content c
             JOIN news_summary s ON s.news_content_id = c.id
             LEFT JOIN news_content_embedding e ON e.news_content_id = c.id
             WHERE c.status = 2
               AND COALESCE(NULLIF(trim(s.summary), ''), '') <> ''
               AND (e.news_content_id IS NULL OR e.status <> 2)
             ORDER BY c.id
             LIMIT {$limit}"
        );

        $n = 0;
        foreach ($rows as $r) {
            $id = (int)($r['id'] ?? 0);
            if ($id <= 0) continue;

            $redis->push(Worker::$large, [
                'action' => 'generate_enrichment',
                'id' => $id,
                'do_summary' => false,
                'do_embedding' => true,
            ]);
            $n++;
        }

        echo "[backfill:embeddings] queued={$n}\n";
        $log->info('queued', ['count' => $n, 'limit' => $limit]);
    }
}

