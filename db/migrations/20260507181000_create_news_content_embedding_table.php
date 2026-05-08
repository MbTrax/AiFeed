<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Util\Literal;

final class CreateNewsContentEmbeddingTable extends AbstractMigration
{
    public function up(): void
    {
        // pgvector extension
        $this->execute('CREATE EXTENSION IF NOT EXISTS vector');

        $table = $this->table('news_content_embedding');
        $table
            ->addColumn('news_content_id', 'integer', ['null' => false])
            ->addColumn('status', 'smallinteger', ['default' => 0])
            ->addColumn('created_at', 'timestamp', ['default' => Literal::from('CURRENT_TIMESTAMP')])
            ->addColumn('updated_at', 'timestamp', ['null' => true])
            ->addForeignKey('news_content_id', 'news_content', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->addIndex(['news_content_id'], ['unique' => true])
            ->create();

        // Add vector column and ANN index.
        $this->execute('ALTER TABLE news_content_embedding ADD COLUMN IF NOT EXISTS embedding vector(1536)');
        $this->execute('CREATE INDEX IF NOT EXISTS news_content_embedding_vector_idx ON news_content_embedding USING hnsw (embedding vector_cosine_ops)');
    }

    public function down(): void
    {
        $this->table('news_content_embedding')->drop()->save();
    }
}
