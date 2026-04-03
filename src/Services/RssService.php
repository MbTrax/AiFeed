<?php

namespace App\Services;

use Exception;

class RssService
{
    public function fetch(string $url): array
    {
        $content = @file_get_contents($url);

        if ($content === false) {
            throw new Exception("Не удалось прочитать RSS канал: $url");
        }

        $xml = simplexml_load_string($content);

        if (!$xml) {
            throw new Exception("Ошибка парсинга XML");
        }

        $news = [];
        foreach ($xml->channel->item as $item) {
            $news[] = [
                'title'       => (string)$item->title,
                'link'        => (string)$item->link,
                'description' => (string)$item->description,
                'pubDate'     => (string)$item->pubDate,
            ];
        }

        return $news;
    }
}