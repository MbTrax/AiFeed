<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class FixEmbeddingVectorDims extends AbstractMigration
{
    public function up(): void
    {
        $this->execute('DROP INDEX IF EXISTS news_content_embedding_vector_idx');
        $this->execute('ALTER TABLE news_content_embedding ALTER COLUMN embedding TYPE vector(1024)');
        $this->execute('CREATE INDEX IF NOT EXISTS news_content_embedding_vector_idx ON news_content_embedding USING hnsw (embedding vector_cosine_ops)');
    }

    public function down(): void
    {
        $this->execute('DROP INDEX IF EXISTS news_content_embedding_vector_idx');
        $this->execute('ALTER TABLE news_content_embedding ALTER COLUMN embedding TYPE vector(1536)');
        $this->execute('CREATE INDEX IF NOT EXISTS news_content_embedding_vector_idx ON news_content_embedding USING hnsw (embedding vector_cosine_ops)');
    }
}

