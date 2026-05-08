<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use Exception;
use League\HTMLToMarkdown\HtmlConverter;

class ParserService
{
    private HtmlConverter $converter;

    public function __construct()
    {
        $this->converter = new HtmlConverter([
            'strip_tags' => true,
            'hard_break' => true,
            'remove_nodes' => 'script style nav header footer figure form aside',
        ]);
    }

    public function parse(string $url, string $contentClass = 'article-content'): array
    {
        [$html, $httpCode, $curlErr] = $this->download($url);
        if ($html === null) {
            $suffix = [];
            if ($httpCode !== null) $suffix[] = "http={$httpCode}";
            if ($curlErr) $suffix[] = "curl={$curlErr}";
            $extra = $suffix ? (' (' . implode(', ', $suffix) . ')') : '';
            throw new Exception("Failed to download content: {$url}{$extra}");
        }

        $doc = new DOMDocument();
        @$doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new DOMXPath($doc);

        $title = $xpath->query('//h1')->item(0)?->nodeValue
            ?? $doc->getElementsByTagName('title')->item(0)?->nodeValue
            ?? 'Untitled';

        $contentNode = $xpath->query("//*[contains(@class, '{$contentClass}')]")->item(0)
            ?? $doc->getElementsByTagName('article')->item(0)
            ?? $doc->getElementsByTagName('body')->item(0);

        if (!$contentNode) {
            throw new Exception('Failed to locate content node');
        }

        // Remove elements we never want to persist/convert.
        // In particular, strip images and links so markdown doesn't contain media or URLs.
        $garbage = $xpath->query('.//script|.//style|.//nav|.//header|.//footer|.//form|.//aside|.//iframe|.//img|.//picture|.//figure|.//source|.//svg|.//a', $contentNode);
        foreach ($garbage as $node) {
            $node->parentNode?->removeChild($node);
        }

        $cleanHtml = $doc->saveHTML($contentNode) ?: '';
        $markdown = $this->converter->convert($cleanHtml);

        // Escape double quotes for safe downstream handling (e.g. prompts, logs, JSON embedding).
        // (No-op if there are no quotes in the text.)
        $markdown = str_replace('"', '\\"', $markdown);
        $cleanHtml = str_replace('"', '\\"', $cleanHtml);

        return [
            'title' => trim((string)$title),
            'markdown' => trim((string)$markdown),
            'html' => trim((string)$cleanHtml),
            'meta' => [
                'source' => $url,
                'length' => mb_strlen((string)$markdown),
                'domain' => parse_url($url, PHP_URL_HOST),
                'http' => $httpCode,
            ],
            'at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return array{0:?string,1:?int,2:?string} [html, httpCode, curlError]
     */
    private function download(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_ENCODING => '',
            // Pragmatic default for Windows: avoid CA bundle issues.
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);

        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch) ?: null;
        curl_close($ch);

        if (!is_string($html) || $html === '') {
            return [null, $httpCode ?: null, $curlErr];
        }

        $cp = mb_detect_encoding($html, 'UTF-8, CP1251', true);
        if ($cp && $cp !== 'UTF-8') {
            $html = mb_convert_encoding($html, 'UTF-8', $cp);
        }

        return [$html, $httpCode ?: null, $curlErr];
    }
}
