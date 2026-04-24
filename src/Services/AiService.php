<?php

namespace App\Services;

use Exception;

class AiService
{

    public $apiUrl;
    public $summariseModel;
    public $embeddingModel;

    public function __construct($config)
    {
        $this->apiUrl = $config['apiUrl'];
        $this->summariseModel = $config['summariseModel'];
        $this->embeddingModel = $config['embeddingModel'];
    }

    public function summarise(string $content): array
    {
        $jsonSchema = [
            "type" => "object",
            "strict" => true,
            "properties" => [
                "tags" => ["type" => "array", "items" => ["type" => "string"]],
                "keyWords" => ["type" => "array", "items" => ["type" => "string"]],
                "summary" => ["type" => "string"]
            ],
            "required" => ["tags", "keyWords", "summary"]
        ];

        $payload = [
            'model' => $this->summariseModel,
            [
                'role' => 'system',
                'content' => "Ты аналитик новостей. Проанализируй текст и верни ответ строго в формате JSON согласно схеме."
            ],
            'messages' => [
                ['role' => 'user', 'content' => $content]
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => "characters",
                    'schema' => $jsonSchema,
                ]
            ],
            'temperature' => 0.1
        ];

        $ch = curl_init($this->apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 1000);

        $response = curl_exec($ch);
        $result = json_decode($response, true);
        $aiRawJson = $result['choices'][0]['message']['content'] ?? null;
        $aiData = json_decode($aiRawJson, true);

        if (!$aiData) {
            throw new Exception("Не удалось распарсить JSON от нейросети.");
        }
        return $aiData;
    }

    public function embedding() : array
    {
        return [];
    }
}