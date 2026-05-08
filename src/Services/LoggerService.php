<?php

namespace App\Services;

class LoggerService
{
    private string $filePath;
    private string $channel;

    public function __construct(string $filePath, string $channel = 'app')
    {
        $this->filePath = $filePath;
        $this->channel = $channel;

        $dir = dirname($this->filePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
    }

    public function withChannel(string $channel): self
    {
        $clone = clone $this;
        $clone->channel = $channel;
        return $clone;
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    public function warn(string $message, array $context = []): void
    {
        $this->write('WARN', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    private function write(string $level, string $message, array $context): void
    {
        $ts = date('Y-m-d H:i:s');
        $pid = getmypid();
        $ctx = $context ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
        $line = "[{$ts}] {$level} {$this->channel} pid={$pid} {$message}{$ctx}\n";
        @file_put_contents($this->filePath, $line, FILE_APPEND | LOCK_EX);
    }
}

