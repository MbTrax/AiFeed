<?php

namespace App\Controllers;

use App\Services\LoggerService;
use App\Services\RedisService;

final class AdminController
{
    private RedisService $redis;
    private array $config;
    private LoggerService $log;

    public function __construct(RedisService $redis, array $config, LoggerService $logger)
    {
        $this->redis = $redis;
        $this->config = $config;
        $this->log = $logger->withChannel('admin');
    }

    public function index(): string
    {
        header('Content-Type: text/html; charset=utf-8');

        return <<<'HTML'
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>AiFeed Admin</title>
  <style>
    :root { color-scheme: light; }
    body { margin: 0; font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial, "Noto Sans", "Liberation Sans", sans-serif; background:#0b0d12; color:#e8edf7; }
    .top { display:flex; align-items:center; justify-content:space-between; padding:12px 16px; background:#0f1420; border-bottom:1px solid #1b2438; }
    .brand { font-weight:650; letter-spacing:.2px; }
    .wrap { max-width: 1180px; margin: 0 auto; padding: 16px; }
    .tabs { display:flex; gap:8px; flex-wrap:wrap; margin: 12px 0 16px; }
    .tab { background:#11182a; border:1px solid #1b2438; color:#cfe0ff; padding:8px 10px; border-radius:6px; cursor:pointer; }
    .tab.active { background:#193056; border-color:#2b4f8d; }
    .grid { display:grid; grid-template-columns: 1fr; gap:12px; }
    @media (min-width: 980px) { .grid { grid-template-columns: 1.1fr .9fr; } }
    .panel { background:#0f1420; border:1px solid #1b2438; border-radius:6px; padding:12px; }
    h2 { font-size:14px; margin:0 0 10px; font-weight:650; color:#e8edf7; }
    label { display:block; font-size:12px; color:#a9b8d6; margin-bottom:6px; }
    input, select, textarea { width:100%; box-sizing:border-box; background:#0b0d12; border:1px solid #1b2438; color:#e8edf7; padding:9px 10px; border-radius:6px; outline:none; }
    textarea { min-height: 340px; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace; font-size:12px; }
    .row { display:flex; gap:8px; align-items:flex-end; }
    .row > * { flex: 1; }
    button { background:#1d3d73; border:1px solid #2b4f8d; color:#e8edf7; padding:9px 10px; border-radius:6px; cursor:pointer; }
    button.ghost { background:#11182a; border-color:#1b2438; color:#cfe0ff; }
    button.danger { background:#5a1e27; border-color:#7c2b38; }
    button:disabled { opacity:.6; cursor:not-allowed; }
    .list { display:flex; flex-direction:column; gap:8px; }
    .item { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:8px 10px; border:1px solid #1b2438; border-radius:6px; background:#0b0d12; }
    .muted { color:#a9b8d6; font-size:12px; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace; font-size:12px; }
    .pill { display:inline-flex; padding:2px 8px; border-radius:999px; border:1px solid #1b2438; background:#11182a; color:#cfe0ff; font-size:12px; }
    .footer { margin-top: 10px; color:#7f90b3; font-size:12px; }
  </style>
</head>
<body>
  <div id="app">
    <div class="top">
      <div class="brand">AiFeed Admin</div>
      <div class="muted mono">/admin</div>
    </div>
    <div class="wrap">
      <div class="tabs">
        <button class="tab" :class="{active: tab==='rss'}" @click="tab='rss'">RSS</button>
        <button class="tab" :class="{active: tab==='queues'}" @click="tab='queues'">Очереди</button>
        <button class="tab" :class="{active: tab==='logs'}" @click="tab='logs'">Логи</button>
      </div>

      <div v-if="tab==='rss'" class="grid">
        <div class="panel">
          <h2>Активные RSS каналы</h2>
          <div class="row" style="margin-bottom:10px;">
            <button class="ghost" @click="loadRss" :disabled="busy.rss">Обновить</button>
            <div class="muted" style="text-align:right;">{{ rss.items.length }} шт.</div>
          </div>
          <div class="list">
            <div v-for="u in rss.items" :key="u" class="item">
              <div class="mono" style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">{{ u }}</div>
              <span class="pill">active</span>
            </div>
            <div v-if="!rss.items.length" class="muted">Пока пусто.</div>
          </div>
          <div class="footer">Источники: env `RSS_URLS` + `storage/rss_urls.json`.</div>
        </div>

        <div class="panel">
          <h2>Добавить RSS канал</h2>
          <label>URL</label>
          <input v-model="rss.newUrl" placeholder="https://example.com/rss" />
          <div class="row" style="margin-top:10px;">
            <button @click="addRss" :disabled="busy.addRss || !rss.newUrl.trim()">Добавить</button>
            <button class="ghost" @click="rss.newUrl=''" :disabled="busy.addRss">Очистить</button>
          </div>
          <div v-if="rss.msg" class="muted" style="margin-top:10px;">{{ rss.msg }}</div>
        </div>
      </div>

      <div v-if="tab==='queues'" class="grid">
        <div class="panel">
          <h2>Очереди Redis</h2>
          <div class="row" style="margin-bottom:10px;">
            <button class="ghost" @click="loadQueues" :disabled="busy.queues">Обновить</button>
            <div class="muted" style="text-align:right;">{{ queues.at }}</div>
          </div>

          <div class="list">
            <div v-for="q in queues.items" :key="q.key" class="item">
              <div>
                <div class="mono">{{ q.key }}</div>
                <div class="muted">тип: {{ q.type }}</div>
              </div>
              <div style="display:flex; align-items:center; gap:8px;">
                <span class="pill">{{ q.len }}</span>
                <button class="danger" @click="clearQueue(q.key)" :disabled="busy.clear">Очистить</button>
              </div>
            </div>
          </div>

          <div v-if="queues.msg" class="muted" style="margin-top:10px;">{{ queues.msg }}</div>
        </div>

        <div class="panel">
          <h2>Превью задач (первые 20)</h2>
          <label>Очередь</label>
          <select v-model="queues.previewKey" @change="loadQueues">
            <option v-for="q in queues.items" :key="q.key" :value="q.key">{{ q.key }}</option>
          </select>
          <div class="muted" style="margin-top:10px;">Показывает list-очереди (LLEN/LRANGE).</div>
          <textarea class="mono" readonly :value="queues.previewText"></textarea>
        </div>
      </div>

      <div v-if="tab==='logs'" class="grid">
        <div class="panel">
          <h2>Логи</h2>
          <div class="row" style="margin-bottom:10px;">
            <div>
              <label>Строк</label>
              <input v-model.number="logs.lines" type="number" min="50" max="5000" step="50" />
            </div>
            <button class="ghost" @click="loadLogs" :disabled="busy.logs">Обновить</button>
            <button class="ghost" @click="autoLogs = !autoLogs; (autoLogs ? startAutoLogs() : stopAutoLogs())">
              {{ autoLogs ? 'Auto: ON' : 'Auto: OFF' }}
            </button>
          </div>
          <div class="muted" style="margin-bottom:10px;">Файл: <span class="mono">{{ logs.file }}</span></div>
          <textarea class="mono" readonly :value="logs.text"></textarea>
          <div class="footer">Последнее обновление: {{ logs.at }}</div>
        </div>

        <div class="panel">
          <h2>Проверка</h2>
          <div class="muted">Статусы и ошибки API будут появляться здесь.</div>
          <textarea class="mono" readonly :value="debug.join('\n')"></textarea>
        </div>
      </div>
    </div>
  </div>

  <script src="https://unpkg.com/vue@3/dist/vue.global.prod.js"></script>
  <script>
    const { createApp } = Vue;

    function nowStr() {
      return new Date().toLocaleString();
    }

    async function api(path, opts = {}) {
      const res = await fetch(path, {
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        ...opts
      });
      const text = await res.text();
      let json = null;
      try { json = JSON.parse(text); } catch (e) {}
      if (!res.ok) {
        const msg = (json && json.error) ? json.error : (text || ('HTTP ' + res.status));
        throw new Error(msg);
      }
      return json ?? {};
    }

    createApp({
      data() {
        return {
          tab: 'rss',
          busy: { rss:false, addRss:false, queues:false, clear:false, logs:false },
          rss: { items:[], newUrl:'', msg:'' },
          queues: { items:[], msg:'', at:'', previewKey:'tasks_large', previewText:'' },
          logs: { file:'', lines:500, text:'', at:'' },
          autoLogs: false,
          autoTimer: null,
          debug: []
        };
      },
      methods: {
        logDebug(s) {
          this.debug.unshift(`[${nowStr()}] ${s}`);
          this.debug = this.debug.slice(0, 200);
        },
        async loadRss() {
          this.busy.rss = true; this.rss.msg = '';
          try {
            const j = await api('/api/rss');
            this.rss.items = j.urls || [];
            this.rss.msg = `OK (${this.rss.items.length})`;
          } catch (e) {
            this.rss.msg = e.message;
            this.logDebug('RSS load: ' + e.message);
          } finally {
            this.busy.rss = false;
          }
        },
        async addRss() {
          this.busy.addRss = true; this.rss.msg = '';
          try {
            const url = this.rss.newUrl.trim();
            const j = await api('/api/rss/add', { method:'POST', body: JSON.stringify({ url }) });
            this.rss.items = j.urls || [];
            this.rss.newUrl = '';
            this.rss.msg = 'Добавлено';
          } catch (e) {
            this.rss.msg = e.message;
            this.logDebug('RSS add: ' + e.message);
          } finally {
            this.busy.addRss = false;
          }
        },
        async loadQueues() {
          this.busy.queues = true; this.queues.msg = '';
          try {
            const j = await api('/api/queues');
            this.queues.items = j.queues || [];
            this.queues.at = j.at || nowStr();
            if (!this.queues.items.find(q => q.key === this.queues.previewKey)) {
              this.queues.previewKey = (this.queues.items[0] && this.queues.items[0].key) ? this.queues.items[0].key : 'tasks_large';
            }
            const p = await api('/api/queues/peek?key=' + encodeURIComponent(this.queues.previewKey));
            this.queues.previewText = (p.items || []).join('\n');
          } catch (e) {
            this.queues.msg = e.message;
            this.logDebug('Queues load: ' + e.message);
          } finally {
            this.busy.queues = false;
          }
        },
        async clearQueue(key) {
          if (!confirm('Очистить очередь ' + key + '?')) return;
          this.busy.clear = true; this.queues.msg = '';
          try {
            await api('/api/queues/clear', { method:'POST', body: JSON.stringify({ key }) });
            this.queues.msg = 'Очищено: ' + key;
            await this.loadQueues();
          } catch (e) {
            this.queues.msg = e.message;
            this.logDebug('Queues clear: ' + e.message);
          } finally {
            this.busy.clear = false;
          }
        },
        async loadLogs() {
          this.busy.logs = true;
          try {
            const j = await api('/api/logs?lines=' + encodeURIComponent(this.logs.lines));
            this.logs.file = j.file || '';
            this.logs.text = j.text || '';
            this.logs.at = j.at || nowStr();
          } catch (e) {
            this.logDebug('Logs load: ' + e.message);
          } finally {
            this.busy.logs = false;
          }
        },
        startAutoLogs() {
          this.stopAutoLogs();
          this.autoTimer = setInterval(() => this.loadLogs(), 2500);
          this.loadLogs();
        },
        stopAutoLogs() {
          if (this.autoTimer) clearInterval(this.autoTimer);
          this.autoTimer = null;
        }
      },
      mounted() {
        this.loadRss();
        this.loadQueues();
        this.loadLogs();
      },
      beforeUnmount() { this.stopAutoLogs(); }
    }).mount('#app');
  </script>
</body>
</html>
HTML;
    }

    public function apiRss(): string
    {
        $urls = (array)($this->config['rss']['urls'] ?? []);
        return $this->json(['urls' => array_values($urls)]);
    }

    public function apiRssAdd(): string
    {
        $body = $this->readJsonBody();
        $url = trim((string)($body['url'] ?? ''));

        if ($url === '' || !preg_match('/^https?:\\/\\//i', $url)) {
            return $this->json(['error' => 'Invalid url'], 400);
        }

        $storageFile = (string)($this->config['rss']['storageFile'] ?? '');
        if ($storageFile === '') {
            return $this->json(['error' => 'RSS storage file not configured'], 500);
        }

        $dir = dirname($storageFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $current = [];
        if (is_file($storageFile)) {
            $raw = @file_get_contents($storageFile);
            if (is_string($raw) && trim($raw) !== '') {
                $json = json_decode($raw, true);
                if (is_array($json)) {
                    $current = $json;
                }
            }
        }

        $current = array_values(array_filter(array_map('trim', is_array($current) ? $current : [])));
        $current[] = $url;
        $current = array_values(array_unique(array_filter($current)));

        $ok = @file_put_contents($storageFile, json_encode($current, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        if ($ok === false) {
            return $this->json(['error' => 'Failed to write rss storage file'], 500);
        }

        $urls = (array)($this->config['rss']['urls'] ?? []);
        $merged = array_values(array_unique(array_filter(array_merge(array_map('trim', $urls), $current))));
        $this->log->info('rss added', ['url' => $url]);
        return $this->json(['urls' => $merged]);
    }

    public function apiQueues(): string
    {
        $keys = [
            ['key' => 'tasks_similar', 'type' => 'list'],
            ['key' => 'tasks_large', 'type' => 'list'],
            ['key' => 'delayed:tasks_similar', 'type' => 'zset'],
            ['key' => 'delayed:tasks_large', 'type' => 'zset'],
        ];

        $out = [];
        foreach ($keys as $k) {
            $len = 0;
            if ($k['type'] === 'list') {
                $len = $this->redis->llen($k['key']);
            } else {
                $len = $this->redis->zCard($k['key']);
            }
            $out[] = ['key' => $k['key'], 'type' => $k['type'], 'len' => $len];
        }

        return $this->json(['queues' => $out, 'at' => date('c')]);
    }

    public function apiQueuesPeek(): string
    {
        $key = trim((string)($_GET['key'] ?? 'tasks_large'));
        if ($key === '') {
            return $this->json(['error' => 'Missing key'], 400);
        }
        $items = $this->redis->lRange($key, 0, 19);
        $items = array_map(static function ($raw) {
            if (!is_string($raw)) {
                return '[non-string]';
            }
            $j = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($j)) {
                return json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            return $raw;
        }, $items);
        return $this->json(['items' => $items]);
    }

    public function apiQueuesClear(): string
    {
        $body = $this->readJsonBody();
        $key = trim((string)($body['key'] ?? ''));
        if ($key === '') {
            return $this->json(['error' => 'Missing key'], 400);
        }

        $n = $this->redis->del($key);
        $this->log->warn('queue cleared', ['key' => $key, 'deleted' => $n]);
        return $this->json(['ok' => true, 'key' => $key, 'deleted' => $n]);
    }

    public function apiLogs(): string
    {
        $file = (string)($this->config['log']['file'] ?? '');
        if ($file === '' || !is_file($file)) {
            return $this->json(['error' => 'Log file not found'], 404);
        }

        $lines = (int)($_GET['lines'] ?? 500);
        if ($lines < 50) $lines = 50;
        if ($lines > 5000) $lines = 5000;

        $text = $this->tailFile($file, $lines);
        return $this->json([
            'file' => $file,
            'lines' => $lines,
            'text' => $text,
            'at' => date('c'),
        ]);
    }

    private function json(array $data, int $status = 200): string
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function readJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $json = json_decode($raw, true);
        return is_array($json) ? $json : [];
    }

    private function tailFile(string $path, int $lines): string
    {
        $fp = @fopen($path, 'rb');
        if (!$fp) {
            return '';
        }

        try {
            $buf = '';
            $chunk = 8192;
            fseek($fp, 0, SEEK_END);
            $pos = ftell($fp);
            $need = $lines + 1;

            while ($pos > 0 && $need > 0) {
                $read = $pos >= $chunk ? $chunk : $pos;
                $pos -= $read;
                fseek($fp, $pos, SEEK_SET);
                $part = fread($fp, $read);
                if (!is_string($part) || $part === '') {
                    break;
                }
                $buf = $part . $buf;
                $need -= substr_count($part, "\n");
            }

            $arr = preg_split("/\r?\n/", $buf);
            if (!is_array($arr)) {
                return '';
            }
            $arr = array_slice($arr, -$lines);
            return rtrim(implode("\n", $arr));
        } finally {
            fclose($fp);
        }
    }
}

