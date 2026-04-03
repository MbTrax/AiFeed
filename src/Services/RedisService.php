<?php

namespace App\Services;

use Redis;
use Exception;

class RedisService
{
    private Redis $redis;
    private array $config;

    public function __construct(array $config)
    {

        $this->config = $config;
        $this->redis = new Redis();
    }

    /**
     * Ленивое подключение к Redis
     */
    private function getClient(): Redis
    {
        try {
            if (!$this->redis->isConnected()) {
                $this->redis->connect(
                    $this->config['host'],
                    $this->config['port']
                );

                if (!empty($this->config['prefix'])) {
                    $this->redis->setOption(Redis::OPT_PREFIX, $this->config['prefix']);
                }

                $this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);
            }
        } catch (Exception $e) {
            throw new Exception("Не удалось подключиться к Redis: " . $e->getMessage());
        }

        return $this->redis;
    }

    /**
     * Добавить задачу в очередь (L-PUSH)
     */
    public function push(string $queue, array $data): void {

        $this->getClient()->lPush($queue, json_encode($data));
    }

    /**
     * Забрать задачу из очереди (B-R-POP — блокирующее чтение)
     * Ожидает появления данных в течение $timeout секунд
     */
    public function pop(string $queue, int $timeout = 5)
    {
        $result = $this->getClient()->brPop([$queue], $timeout);

        // brPop возвращает [имя_очереди, данные]
        return $result ? $result[1] : null;
    }

    /**
     * Обычный кэш: записать значение
     */
    public function set(string $key, $value, int $ttl = 3600): void
    {
        $this->getClient()->set($key, $value, $ttl);
    }

    /**
     * Обычный кэш: прочитать значение
     */
    public function get(string $key)
    {
        return $this->getClient()->get($key);
    }
}