<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use League\HTMLToMarkdown\HtmlConverter;
use Exception;

class ParserService
{
    private HtmlConverter $converter;

    public function __construct()
    {
        $this->converter = new HtmlConverter([
            'strip_tags' => true,
            'hard_break' => true,
            // Сюда можно добавить теги, которые Markdown-конвертер должен игнорировать
            'remove_nodes' => 'script style nav header footer figure form aside',
        ]);
    }

    public function parse(string $url, string $contentClass = 'article-content'): array
    {
        $html = $this->download($url);
        if (!$html) {
            throw new Exception("Не удалось загрузить контент по ссылке: $url");
        }

        $doc = new DOMDocument();
        // Используем хак с XML-префиксом для корректной работы UTF-8 в DOMDocument
        @$doc->loadHTML('<?xml encoding="UTF-8">' . $html);

        $xpath = new DOMXPath($doc);

        // 1. Извлекаем заголовок
        $title = $xpath->query('//h1')->item(0)?->nodeValue
            ?? $doc->getElementsByTagName('title')->item(0)?->nodeValue
            ?? 'Untitled';

        // 2. Ищем основной блок контента
        $contentNode = $xpath->query("//*[contains(@class, '$contentClass')]")->item(0)
            ?? $doc->getElementsByTagName('article')->item(0)
            ?? $doc->getElementsByTagName('body')->item(0);

        if (!$contentNode) {
            throw new Exception("Не удалось найти блок контента на странице");
        }

        // 3. Очистка узла от мусора (скрипты, стили и т.д.)
        // Мы делаем это ДО сохранения HTML, чтобы в базу попал чистый код
        $garbage = $xpath->query('.//script|.//style|.//nav|.//header|.//footer|.//form|.//aside|.//iframe', $contentNode);
        foreach ($garbage as $node) {
            $node->parentNode->removeChild($node);
        }

        // 4. Получаем очищенный HTML
        // saveHTML($contentNode) вернет только внутренний код выбранного блока
        $cleanHtml = $doc->saveHTML($contentNode);

        // 5. Конвертируем этот же очищенный HTML в Markdown
        $markdown = $this->converter->convert($cleanHtml);

        // Возвращаем полный набор данных для news_content
        return [
            'title'    => trim($title),
            'markdown' => trim($markdown),
            'html'     => trim($cleanHtml), // Для колонки html_content
            'meta'     => [                  // Для колонки meta_data (JSON)
                'source' => $url,
                'length' => mb_strlen($markdown),
                'domain' => parse_url($url, PHP_URL_HOST)
            ],
            'at'       => date('Y-m-d H:i:s')
        ];
    }

    private function download(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_ENCODING       => '',
        ]);
        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$html || $httpCode !== 200) return null;

        $cp = mb_detect_encoding($html, 'UTF-8, CP1251', true);
        if ($cp && $cp !== 'UTF-8') {
            $html = mb_convert_encoding($html, 'UTF-8', $cp);
        }

        return $html;
    }
}