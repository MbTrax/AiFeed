<?php
namespace App\Bin;

class Composer {
    public function __construct($container) {
        $this->redis = $container->make('redis');
        $this->db = $container->make('db');
    }

    public function run() {
        echo "[Composer] Запущен. Ищу новые записи...\n";

        while (true) {
            $news = $this->db->fetchAll(
                "SELECT id, link FROM news WHERE status = 0 LIMIT 10 FOR UPDATE SKIP LOCKED"
            );

            if ($news) {
                foreach ($news as $item) {
                    $this->redis->push(Worker::$similar, [
                        'action' => 'parse_full_text',
                        'id'     => $item['id'],
                        'link'   => $item['link']
                    ]);

                    $this->db->query("UPDATE news SET status = 1 WHERE id = ?", [$item['id']]);
                    echo "[Composer] ID {$item['id']} отправлен в обработку.\n";
                }
            }

            $content = $this->db->fetchAll(
                "SELECT id FROM news_content WHERE status = 0 LIMIT 10 FOR UPDATE SKIP LOCKED"
            );

            if ($content) {
                foreach ($content as $item) {
                    $this->redis->push(Worker::$large, [
                        'action' => 'generate_summary',
                        'id'     => $item['id'],
                    ]);

                    $this->db->query("UPDATE news_content SET status = 1 WHERE id = ?", [$item['id']]);
                    echo "[Composer] ID {$item['id']} отправлен в обработку.\n";
                }
            }

            sleep(100);
        }
    }
}