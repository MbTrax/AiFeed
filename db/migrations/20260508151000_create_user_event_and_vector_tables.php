<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Util\Literal;

final class CreateUserEventAndVectorTables extends AbstractMigration
{
    public function up(): void
    {
        $this->execute('CREATE EXTENSION IF NOT EXISTS vector');

        $event = $this->table('user_event');
        $event
            ->addColumn('subject_type', 'string', ['limit' => 16, 'null' => false]) // user|cookie
            ->addColumn('subject_id', 'string', ['limit' => 128, 'null' => false])
            ->addColumn('url', 'text', ['null' => false])
            ->addColumn('news_content_id', 'integer', ['null' => true])
            ->addColumn('duration_sec', 'integer', ['default' => 0])
            ->addColumn('activity', 'jsonb', ['null' => true])
            ->addColumn('weight', 'float', ['default' => 0])
            ->addColumn('created_at', 'timestamp', ['default' => Literal::from('CURRENT_TIMESTAMP')])
            ->addIndex(['subject_type', 'subject_id'])
            ->addIndex(['news_content_id'])
            ->create();

        $this->table('user_event')
            ->addForeignKey('news_content_id', 'news_content', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->save();

        $vec = $this->table('user_vector');
        $vec
            ->addColumn('subject_type', 'string', ['limit' => 16, 'null' => false])
            ->addColumn('subject_id', 'string', ['limit' => 128, 'null' => false])
            ->addColumn('weight_sum', 'float', ['default' => 0])
            ->addColumn('created_at', 'timestamp', ['default' => Literal::from('CURRENT_TIMESTAMP')])
            ->addColumn('updated_at', 'timestamp', ['null' => true])
            ->addIndex(['subject_type', 'subject_id'], ['unique' => true])
            ->create();

        $this->execute('ALTER TABLE user_vector ADD COLUMN IF NOT EXISTS embedding vector(1024)');
        $this->execute('CREATE INDEX IF NOT EXISTS user_vector_embedding_idx ON user_vector USING hnsw (embedding vector_cosine_ops)');
    }

    public function down(): void
    {
        $this->execute('DROP INDEX IF EXISTS user_vector_embedding_idx');
        $this->table('user_event')->drop()->save();
        $this->table('user_vector')->drop()->save();
    }
}

