<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateNewsTable extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('news');
        $table->addColumn('link', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('title', 'string', ['limit' => 500, 'null' => false])
            ->addColumn('description', 'text', ['limit' => 1024, 'null' => false])
            ->addColumn('status', 'smallinteger', ['default' => 0])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'timestamp', ['null' => true, 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['news_id'], ['unique' => true])
            ->addIndex(['link'], ['unique' => true])
            ->create();
    }
}
