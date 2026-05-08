<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateNewsSummaryTable extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('news_summary');
        $table->addColumn('news_content_id', 'integer', ['null' => false])
            // body (JSON) — в Postgres используем jsonb для скорости и поиска
            ->addColumn('body', 'jsonb', ['null' => true])
            // keywords, summary, title — текстовые поля
            ->addColumn('keywords', 'text', ['null' => true])
            ->addColumn('summary', 'text', ['null' => true])
            ->addColumn('title', 'string', ['limit' => 500, 'null' => true])
            // entities, tags — если это списки, jsonb тоже отлично подойдет
            ->addColumn('entities', 'jsonb', ['null' => true])
            ->addColumn('tags', 'jsonb', ['null' => true])
            // статус и даты
            ->addColumn('status', 'smallinteger', ['default' => 0])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'timestamp', ['null' => true, 'update' => 'CURRENT_TIMESTAMP'])

            // Связь с news_content
            // Если запись в news_content удаляется, саммари тоже удалится
            ->addForeignKey('news_content_id', 'news_content', 'id', ['delete'=> 'CASCADE', 'update'=> 'NO_ACTION'])
            ->create();
    }
}
