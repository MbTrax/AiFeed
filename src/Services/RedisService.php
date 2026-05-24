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
     * Best-effort distributed lock using SET key value NX EX ttl.
     * Returns lock token on success, null on contention.
     */
    public function acquireLock(string $key, int $ttlSec = 60): ?string
    {
        $token = bin2hex(random_bytes(16));
        $ok = $this->getClient()->set($key, $token, ['nx', 'ex' => $ttlSec]);
        return $ok ? $token : null;
    }

    public function releaseLock(string $key, string $token): void
    {
        // Release only if token matches (Lua for atomicity).
        $lua = "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end";
        try {
            $this->getClient()->eval($lua, [$key, $token], 1);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    /**
     * Обычный кэш: прочитать значение
     */
    public function get(string $key)
    {
        return $this->getClient()->get($key);
    }

    public function zAdd(string $key, int $score, string $member): void
    {
        $this->getClient()->zAdd($key, $score, $member);
    }

    public function zRangeByScore(string $key, int $min, int $max, int $limit = 100): array
    {
        return $this->getClient()->zRangeByScore($key, $min, $max, ['limit' => [0, $limit]]);
    }

    public function zRem(string $key, string $member): void
    {
        $this->getClient()->zRem($key, $member);
    }

    public function llen(string $key): int
    {
        $n = $this->getClient()->lLen($key);
        return is_int($n) ? $n : 0;
    }

    public function lRange(string $key, int $start = 0, int $stop = 20): array
    {
        $res = $this->getClient()->lRange($key, $start, $stop);
        return is_array($res) ? $res : [];
    }

    public function zCard(string $key): int
    {
        $n = $this->getClient()->zCard($key);
        return is_int($n) ? $n : 0;
    }

    public function del(string ...$keys): int
    {
        if (!$keys) {
            return 0;
        }
        $n = $this->getClient()->del($keys);
        return is_int($n) ? $n : 0;
    }
}
