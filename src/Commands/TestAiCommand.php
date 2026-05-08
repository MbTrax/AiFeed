<?php

namespace App\Commands;

use App\Core\Command;
use Exception;

class TestAiCommand extends Command
{
    public function execute(array $args): void
    {
        $log = $this->container->make('logger')->withChannel('test');
        $ai = $this->container->make('aiService');

        $text = $args[0] ?? 'Компания SpaceX успешно запустила ракету Starship в шестой тестовый полет.';
        $log->info('test:ai start', ['len' => mb_strlen($text)]);

        echo "[test:ai] start apiUrl={$ai->apiUrl} model={$ai->summariseModel}\n";

        try {
            $data = $ai->summarise($text);
            echo "[test:ai] OK summary_len=" . mb_strlen((string)($data['summary'] ?? '')) . "\n";
            $log->info('test:ai ok');

            if (is_string($ai->embeddingModel) && trim($ai->embeddingModel) !== '' && is_string($ai->embeddingsUrl) && trim($ai->embeddingsUrl) !== '') {
                $vec = $ai->embedding($text);
                echo "[test:ai] OK embedding_len=" . count($vec) . "\n";
                $log->info('test:ai embedding ok', ['len' => count($vec)]);
            } else {
                echo "[test:ai] embedding: SKIP (AI_EMBEDDING_MODEL or AI_EMBEDDINGS_URL empty)\n";
            }
        } catch (Exception $e) {
            echo "[test:ai] FAIL " . $e->getMessage() . "\n";
            $log->error('test:ai fail: ' . $e->getMessage());
        }
    }
}
