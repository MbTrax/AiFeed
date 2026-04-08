<?php

use Phinx\Migration\AbstractMigration;

class CreateNewsEmbeddingTable extends AbstractMigration
{
    /**
     * Команды, выполняемые при применении миграции (migrate)
     */
    public function up(): void
    {
        // 1. Включаем расширение pgvector
        $this->execute('CREATE EXTENSION IF NOT EXISTS vector');

        // 2. Создаем структуру таблицы
        $table = $this->table('news_embedding');
        $table->addColumn('news_summary_id', 'integer', ['null' => false])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'timestamp', ['null' => true, 'update' => 'CURRENT_TIMESTAMP'])
            ->addForeignKey('news_summary_id', 'news_summary', 'id', ['delete'=> 'CASCADE', 'update'=> 'NO_ACTION'])
            ->create();

        // 3. Добавляем колонку embedding (используем IF NOT EXISTS для безопасности)
        $this->execute('ALTER TABLE news_embedding ADD COLUMN IF NOT EXISTS embedding vector(1536)');

        // 4. Создаем HNSW индекс
        $this->execute('CREATE INDEX IF NOT EXISTS news_embedding_vector_idx ON news_embedding USING hnsw (embedding vector_cosine_ops)');
    }

    /**
     * Команды, выполняемые при откате миграции (rollback)
     */
    public function down(): void
    {
        // При откате идем в обратном порядке:

        // 1. Удаляем таблицу (это автоматически удалит индексы и колонку embedding)
        if ($this->hasTable('news_embedding')) {
            $this->table('news_embedding')->drop()->save();
        }

        // Примечание: 'CREATE EXTENSION' обычно не откатывают,
        // так как оно может использоваться другими таблицами.
    }
}