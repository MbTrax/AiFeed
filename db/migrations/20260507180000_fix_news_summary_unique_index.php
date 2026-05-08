<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class FixNewsSummaryUniqueIndex extends AbstractMigration
{
    public function up(): void
    {
        // Remove duplicates by keeping the newest row per news_content_id.
        $this->execute(<<<'SQL'
DELETE FROM news_summary a
USING news_summary b
WHERE a.news_content_id = b.news_content_id
  AND a.id < b.id;
SQL);

        // Ensure ON CONFLICT (news_content_id) works.
        $this->execute(<<<'SQL'
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1
    FROM pg_index i
    JOIN pg_class t ON t.oid = i.indrelid
    JOIN pg_namespace n ON n.oid = t.relnamespace
    JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(i.indkey)
    WHERE n.nspname = current_schema()
      AND t.relname = 'news_summary'
      AND i.indisunique
      AND a.attname = 'news_content_id'
  ) THEN
    CREATE UNIQUE INDEX news_summary_news_content_id_unique ON news_summary (news_content_id);
  END IF;
END $$;
SQL);
    }

    public function down(): void
    {
        // Non-destructive rollback: keep data, drop index if it exists.
        $this->execute('DROP INDEX IF EXISTS news_summary_news_content_id_unique');
    }
}

