<?php

namespace App\Controllers;

use App\Services\DatabaseService;
use App\Services\LoggerService;

final class TrackingController
{
    private DatabaseService $db;
    private array $config;
    private LoggerService $log;

    public function __construct(DatabaseService $db, array $config, LoggerService $logger)
    {
        $this->db = $db;
        $this->config = $config;
        $this->log = $logger->withChannel('tracking');
    }

    public function script(): string
    {
        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: no-store');

        $endpoint = '/api/track';
        $cookieName = 'aifeed_uid';

        return "(function(){\n" .
            "  var ENDPOINT=" . json_encode($endpoint) . ";\n" .
            "  var COOKIE=" . json_encode($cookieName) . ";\n" .
            "  function getCookie(name){\n" .
            "    var m=document.cookie.match(new RegExp('(?:^|; )'+name.replace(/([.$?*|{}()\\[\\]\\\\\\/\\+^])/g,'\\\\$1')+'=([^;]*)'));\n" .
            "    return m?decodeURIComponent(m[1]):'';\n" .
            "  }\n" .
            "  function setCookie(name,value,maxAgeSec){\n" .
            "    var s=name+'='+encodeURIComponent(value)+'; path=/; SameSite=Lax; max-age='+(maxAgeSec||31536000);\n" .
            "    if(location.protocol==='https:'){ s+='; Secure'; }\n" .
            "    document.cookie=s;\n" .
            "  }\n" .
            "  function uuid(){\n" .
            "    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();\n" .
            "    var s='xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g,function(c){var r=Math.random()*16|0,v=c==='x'?r:(r&0x3|0x8);return v.toString(16)});\n" .
            "    return s;\n" .
            "  }\n" .
            "  function canonicalUrl(){\n" .
            "    try{ return location.origin + location.pathname; }catch(e){ return location.href.split('#')[0].split('?')[0]; }\n" .
            "  }\n" .
            "  function send(payload){\n" .
            "    try{\n" .
            "      var body=JSON.stringify(payload);\n" .
            "      if(navigator.sendBeacon){\n" .
            "        var blob=new Blob([body],{type:'application/json'});\n" .
            "        navigator.sendBeacon(ENDPOINT, blob);\n" .
            "        return;\n" .
            "      }\n" .
            "      fetch(ENDPOINT,{method:'POST',mode:'cors',credentials:'omit',headers:{'Content-Type':'application/json'},body:body,keepalive:true});\n" .
            "    }catch(e){}\n" .
            "  }\n" .
            "  var cookieId=getCookie(COOKIE);\n" .
            "  if(!cookieId){ cookieId=uuid(); setCookie(COOKIE,cookieId,31536000); }\n" .
            "  var userId=(document.currentScript && document.currentScript.dataset && document.currentScript.dataset.userId) ? String(document.currentScript.dataset.userId) : '';\n" .
            "  var startedAt=Date.now();\n" .
            "  var active={};\n" .
            "  var maxScroll=0;\n" .
            "  function onScroll(){\n" .
            "    var h=(window.scrollY||0)+(window.innerHeight||0);\n" .
            "    if(h>maxScroll) maxScroll=h;\n" .
            "  }\n" .
            "  window.addEventListener('scroll', onScroll, {passive:true});\n" .
            "  function flush(reason){\n" .
            "    var dur=Math.max(0, Math.round((Date.now()-startedAt)/1000));\n" .
            "    var payload={\n" .
            "      v:1,\n" .
            "      reason: reason||'unload',\n" .
            "      cookieId: cookieId,\n" .
            "      userId: userId,\n" .
            "      url: canonicalUrl(),\n" .
            "      durationSec: dur,\n" .
            "      activity: Object.assign({}, active, { scrollHeight: maxScroll })\n" .
            "    };\n" .
            "    send(payload);\n" .
            "  }\n" .
            "  window.addEventListener('pagehide', function(){ flush('pagehide'); });\n" .
            "  window.addEventListener('visibilitychange', function(){ if(document.visibilityState==='hidden'){ flush('hidden'); } });\n" .
            "  window.AiFeedTrack={\n" .
            "    activity: function(obj){\n" .
            "      if(!obj||typeof obj!=='object') return;\n" .
            "      for(var k in obj){ if(Object.prototype.hasOwnProperty.call(obj,k)){ active[k]=obj[k]; } }\n" .
            "    },\n" .
            "    flush: function(reason){ flush(reason||'manual'); }\n" .
            "  };\n" .
            "})();\n";
    }

    public function collect(): string
    {
        $this->cors();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            http_response_code(204);
            return '';
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return $this->json(['error' => 'Method not allowed'], 405);
        }

        $body = $this->readJsonBody();
        $cookieId = trim((string)($body['cookieId'] ?? ''));
        $userId = trim((string)($body['userId'] ?? ''));
        $url = $this->canonicalUrl((string)($body['url'] ?? ''));
        $durationSec = (int)($body['durationSec'] ?? 0);
        $activity = $body['activity'] ?? null;
        if (!is_array($activity)) {
            $activity = [];
        }

        if ($cookieId === '' && $userId === '') {
            return $this->json(['error' => 'Missing user id'], 400);
        }
        if ($url === '') {
            return $this->json(['error' => 'Missing url'], 400);
        }
        if ($durationSec < 0) $durationSec = 0;
        if ($durationSec > 86400) $durationSec = 86400;

        $subjectType = $userId !== '' ? 'user' : 'cookie';
        $subjectId = $userId !== '' ? $userId : $cookieId;

        $newsContentId = $this->resolveNewsContentId($url);

        $weight = $this->computeWeight($durationSec, $activity);

        $this->db->query(
            "INSERT INTO user_event (subject_type, subject_id, url, news_content_id, duration_sec, activity, weight, created_at)
             VALUES (?, ?, ?, ?, ?, ?::jsonb, ?, CURRENT_TIMESTAMP)",
            [
                $subjectType,
                $subjectId,
                $url,
                $newsContentId ? (int)$newsContentId : null,
                $durationSec,
                json_encode($activity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $weight,
            ]
        );

        if ($newsContentId && $weight > 0) {
            $vec = $this->loadContentVector((int)$newsContentId);
            if (is_array($vec) && $vec) {
                $this->upsertUserVector($subjectType, $subjectId, $vec, $weight);
            }
        }

        return $this->json(['ok' => true]);
    }

    public function loginMerge(): string
    {
        $this->cors();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            http_response_code(204);
            return '';
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return $this->json(['error' => 'Method not allowed'], 405);
        }

        $body = $this->readJsonBody();
        $cookieId = trim((string)($body['cookieId'] ?? ''));
        $userId = trim((string)($body['userId'] ?? ''));
        if ($cookieId === '' || $userId === '') {
            return $this->json(['error' => 'cookieId and userId required'], 400);
        }

        $this->db->query(
            "UPDATE user_event
             SET subject_type = 'user', subject_id = ?
             WHERE subject_type = 'cookie' AND subject_id = ?",
            [$userId, $cookieId]
        );

        $cookieRow = $this->db->fetchOne("SELECT embedding, weight_sum FROM user_vector WHERE subject_type='cookie' AND subject_id = ?", [$cookieId]);
        $userRow = $this->db->fetchOne("SELECT embedding, weight_sum FROM user_vector WHERE subject_type='user' AND subject_id = ?", [$userId]);

        $cookieVec = is_array($cookieRow) ? $this->parseVector((string)($cookieRow['embedding'] ?? '')) : null;
        $cookieW = is_array($cookieRow) ? (float)($cookieRow['weight_sum'] ?? 0) : 0.0;
        $userVec = is_array($userRow) ? $this->parseVector((string)($userRow['embedding'] ?? '')) : null;
        $userW = is_array($userRow) ? (float)($userRow['weight_sum'] ?? 0) : 0.0;

        if (is_array($cookieVec) && $cookieVec && $cookieW > 0) {
            if (!is_array($userVec) || !$userVec || $userW <= 0) {
                $this->writeUserVector('user', $userId, $cookieVec, $cookieW);
            } else {
                $merged = $this->mergeVectors($userVec, $userW, $cookieVec, $cookieW);
                $this->writeUserVector('user', $userId, $merged['vec'], $merged['w']);
            }
        }

        return $this->json(['ok' => true]);
    }

    private function cors(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, OPTIONS, GET');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 86400');
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

    private function canonicalUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') return '';
        // Drop query + fragment.
        $url = preg_split('/[?#]/', $url, 2)[0] ?? $url;
        return trim($url);
    }

    private function resolveNewsContentId(string $url): ?int
    {
        $row = $this->db->fetchOne(
            "SELECT c.id
             FROM news n
             JOIN news_content c ON c.news_id = n.id
             WHERE split_part(split_part(n.link, '?', 1), '#', 1) = ?
             LIMIT 1",
            [$url]
        );
        if (is_array($row) && isset($row['id'])) {
            return (int)$row['id'];
        }
        return null;
    }

    private function computeWeight(int $durationSec, array $activity): float
    {
        $w = 0.0;

        if ($durationSec >= 2) {
            $w += min(2.0, $durationSec / 20.0);
        }

        if (!empty($activity['like'])) $w += 1.0;
        if (!empty($activity['comment'])) $w += 1.5;

        $scrollH = isset($activity['scrollHeight']) ? (int)$activity['scrollHeight'] : 0;
        if ($scrollH > 800) $w += 0.5;
        if ($scrollH > 1400) $w += 0.5;

        if ($w > 5.0) $w = 5.0;
        return $w;
    }

    private function loadContentVector(int $newsContentId): ?array
    {
        $row = $this->db->fetchOne(
            "SELECT embedding
             FROM news_content_embedding
             WHERE news_content_id = ? AND status = 2
             LIMIT 1",
            [$newsContentId]
        );
        if (!is_array($row)) return null;
        $vec = $this->parseVector((string)($row['embedding'] ?? ''));
        return (is_array($vec) && $vec) ? $vec : null;
    }

    private function upsertUserVector(string $type, string $id, array $contentVec, float $w): void
    {
        $row = $this->db->fetchOne(
            "SELECT embedding, weight_sum
             FROM user_vector
             WHERE subject_type = ? AND subject_id = ?
             LIMIT 1",
            [$type, $id]
        );

        if (is_array($row) && isset($row['embedding'])) {
            $curVec = $this->parseVector((string)($row['embedding'] ?? ''));
            $curW = (float)($row['weight_sum'] ?? 0);
            if (is_array($curVec) && $curVec && $curW > 0) {
                $merged = $this->mergeVectors($curVec, $curW, $contentVec, $w);
                $this->writeUserVector($type, $id, $merged['vec'], $merged['w']);
                return;
            }
        }

        $this->writeUserVector($type, $id, $contentVec, $w);
    }

    private function writeUserVector(string $type, string $id, array $vec, float $wSum): void
    {
        $vecLiteral = '[' . implode(',', array_map(static fn($v) => is_numeric($v) ? (string)$v : '0', $vec)) . ']';
        $this->db->query(
            "INSERT INTO user_vector (subject_type, subject_id, embedding, weight_sum, updated_at)
             VALUES (?, ?, ?::vector, ?, CURRENT_TIMESTAMP)
             ON CONFLICT (subject_type, subject_id) DO UPDATE SET
                embedding = EXCLUDED.embedding,
                weight_sum = EXCLUDED.weight_sum,
                updated_at = CURRENT_TIMESTAMP",
            [$type, $id, $vecLiteral, $wSum]
        );
    }

    private function mergeVectors(array $a, float $aW, array $b, float $bW): array
    {
        $n = min(count($a), count($b));
        if ($n <= 0) {
            return ['vec' => [], 'w' => 0.0];
        }
        $w = $aW + $bW;
        if ($w <= 0) $w = 1.0;
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[$i] = (($a[$i] * $aW) + ($b[$i] * $bW)) / $w;
        }
        return ['vec' => $out, 'w' => ($aW + $bW)];
    }

    private function parseVector(string $pgVector): ?array
    {
        $t = trim($pgVector);
        if ($t === '') return null;
        $t = trim($t, "[] \t\r\n");
        if ($t === '') return null;
        $parts = explode(',', $t);
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '') continue;
            $out[] = (float)$p;
        }
        return $out ?: null;
    }
}

