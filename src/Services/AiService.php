<?php

namespace App\Services;

use Exception;

class AiService
{
    public string $apiUrl;
    public string $embeddingsUrl;
    public string $summariseModel;
    public string $embeddingModel;

    public function __construct($config)
    {
        $this->apiUrl = (string)($config['apiUrl'] ?? '');
        $this->embeddingsUrl = (string)($config['embeddingsUrl'] ?? '');
        $this->summariseModel = (string)($config['summariseModel'] ?? '');
        $this->embeddingModel = (string)($config['embeddingModel'] ?? '');
    }

    public function summarise(string $content): array
    {
        $jsonSchema = [
            'type' => 'object',
            'properties' => [
                'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                'keyWords' => ['type' => 'array', 'items' => ['type' => 'string']],
                'summary' => ['type' => 'string'],
            ],
            'required' => ['tags', 'keyWords', 'summary'],
            'additionalProperties' => false,
        ];

        $system = 'Ты аналитик новостей. Проанализируй текст и верни ответ строго в формате JSON согласно схеме. ' .
            'Ограничения: tags максимум 10 элементов, keyWords максимум 10 элементов, summary 2-4 предложения. ' .
            'Никакого текста вне JSON.';
        $content = str_replace(["\r", "\t"], " ", $content);
        $content = preg_replace('/\s+/', ' ', $content); // Схлопываем множественные пробелы в один
        $content = trim($content);
        $payload = [
            'model' => $this->summariseModel,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $content],
            ],
            // Some OpenAI-compatible servers ignore response_format; we still send it.
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'news_summary',
                    'strict' => true,
                    'schema' => $jsonSchema,
                ],
            ],
            'temperature' => 0.1,
            // LM Studio/OpenAI-compatible servers use max_tokens; ensure response isn't truncated mid-JSON.
            'max_tokens' => 800,
        ];

        $maxAttempts = 5;
        $attempt = 0;
        $lastHttp = null;
        $lastCurl = '';
        $response = null;

        while ($attempt < $maxAttempts) {
            $attempt++;

            $ch = curl_init($this->apiUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Accept: application/json',
                'Expect:',
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 1000);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            curl_setopt($ch, CURLOPT_PROXY, '');

            $resp = curl_exec($ch);
            $curlErr = curl_error($ch) ?: '';
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $lastHttp = $httpCode ?: null;
            $lastCurl = $curlErr;
            $response = $resp;

            // Retry on transient errors.
            if ($httpCode === 503 || $httpCode === 502 || $httpCode === 504 || $httpCode === 429 || $curlErr !== '') {
                if ($attempt < $maxAttempts) {
                    // Exponential backoff: 0.5s, 1s, 2s, 4s...
                    $sleepMs = (int)(500 * (2 ** ($attempt - 1)));
                    usleep($sleepMs * 1000);
                    continue;
                }
            }

            break;
        }

        if (!is_string($response) || $response === '') {
            $http = $lastHttp ?? 0;
            throw new Exception("AI request failed (http={$http}, curl={$lastCurl})");
        }

        if (is_int($lastHttp) && $lastHttp >= 400) {
            $snippet = mb_substr($response, 0, 800);
            throw new Exception("AI request failed (http={$lastHttp}). Body: {$snippet}");
        }

        $result = json_decode($response, true);
        if (!is_array($result)) {
            $snippet = mb_substr($response, 0, 500);
            $http = $lastHttp ?? 0;
            throw new Exception("AI returned non-JSON response (http={$http}): {$snippet}");
        }

        $aiText =
            $result['choices'][0]['message']['content']
            ?? $result['choices'][0]['text']
            ?? null;

        if (!is_string($aiText) || trim($aiText) === '') {
            $http = $lastHttp ?? 0;
            throw new Exception("AI response missing content (http={$http})");
        }

        $jsonText = $this->extractJson($aiText);
        $aiData = json_decode($jsonText, true);
        if (!is_array($aiData)) {
            $snippet = mb_substr($aiText, 0, 500);
            throw new Exception("Не удалось распарсить JSON от нейросети. Snippet: {$snippet}");
        }

        return $aiData;
    }

    private function extractJson(string $text): string
    {
        $t = trim($text);

        if (preg_match('/^```(?:json)?\\s*(.*?)\\s*```\\s*$/is', $t, $m)) {
            $t = trim($m[1]);
        }

        $start = strpos($t, '{');
        $end = strrpos($t, '}');
        if ($start !== false && $end !== false && $end > $start) {
            return substr($t, $start, $end - $start + 1);
        }

        return $t;
    }

    public function embedding(string $input): array
    {
        $payload = [
            'model' => $this->embeddingModel,
            'input' => $input,
        ];

        $ch = curl_init($this->embeddingsUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            'Expect:',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 1000);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_PROXY, '');

        $response = curl_exec($ch);
        $curlErr = curl_error($ch) ?: '';
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($response) || $response === '') {
            throw new Exception("AI embeddings request failed (http={$httpCode}, curl={$curlErr})");
        }

        $json = json_decode($response, true);
        if (!is_array($json)) {
            $snippet = mb_substr($response, 0, 500);
            throw new Exception("AI embeddings returned non-JSON response (http={$httpCode}): {$snippet}");
        }

        $vec = $json['data'][0]['embedding'] ?? null;
        if (!is_array($vec) || !$vec) {
            throw new Exception("AI embeddings response missing embedding (http={$httpCode})");
        }

        return $vec;
    }
}
