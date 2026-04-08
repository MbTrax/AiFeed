<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateNewsContentTable extends AbstractMigration
{
    /**
     * Change Method.
     *
     * Write your reversible migrations using this method.
     *
     * More information on writing migrations is available here:
     * https://book.cakephp.org/phinx/0/en/migrations.html#the-change-method
     *
     * Remember to call "create()" or "update()" and NOT "save()" when working
     * with the Table class.
     */
    public function change(): void
    {
        $table = $this->table('news_content');
        $table->addColumn('news_id', 'integer', ['null' => false])
            ->addColumn('content', 'text', ['null' => true])
            ->addColumn('html', 'text', ['null' => true])
            // В Postgres лучше использовать jsonb для мета-данных
            ->addColumn('meta_data', 'jsonb', ['null' => true])
            ->addColumn('status', 'smallinteger', ['default' => 0])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'timestamp', ['null' => true, 'update' => 'CURRENT_TIMESTAMP'])
            ->addForeignKey('news_id', 'news', 'id', ['delete'=> 'CASCADE', 'update'=> 'NO_ACTION'])
            ->addIndex(['news_id'], ['unique' => true]) // ОБЯЗАТЕЛЬНО для ON CONFLICT
            ->create();
    }
}
