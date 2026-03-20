<?php
namespace App;
use DOMDocument;
use DOMXPath;
use League\HTMLToMarkdown\HtmlConverter;

class Parser {
    public $title;
    public $content;
    public $url;

    public function load(string $url) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
        $html = curl_exec($ch);
        curl_close($ch);

        if (!$html) return false;

        $html = mb_convert_encoding($html, 'UTF-8', mb_detect_encoding($html, 'UTF-8, CP1251', true));

        $doc = new DOMDocument();

        @$doc->loadHTML('<?xml encoding="UTF-8">' . $html);

        $this->title = $doc->getElementsByTagName('title')->item(0)->nodeValue ?? 'Untitled';
        $finder = new DomXPath($doc);
        $classname="article-content";
        $body = $finder->query("//*[contains(@class, '$classname')]")->item(0) ?: $doc->getElementsByTagName('body')->item(0);
//        $body = $doc->getElementsByTagName('article')->item(0) ?: $doc->getElementsByTagName('body')->item(0);

        if ($body) {
            // Удаляем мусор (скрипты, стили, навигацию)
            $xpath = new DOMXPath($doc);
            foreach ($xpath->query('//script|//style|//nav|//header|//footer//img//a') as $node) {
                $node->parentNode->removeChild($node);
            }

            // Получаем очищенный HTML контента
            $cleanHtml = $doc->saveHTML($body);

            // Конвертируем в Markdown
            $converter = new HtmlConverter([
                'strip_tags' => true,
                'hard_break' => true
            ]);
            $this->markdown = $converter->convert($cleanHtml);
        }

        return true;
    }
}