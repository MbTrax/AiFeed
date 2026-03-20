<?php

namespace App\Models;

use PDO;
use Exception;

class MaterialsQueue
{
    private PDO $db;
    const table = 'materials_query';
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Добавить новую ссылку в очередь
     */
    public function enqueue(string $url, string $class): bool
    {
        $sql = "INSERT INTO materials_query (url, class, status) 
                VALUES (:url, :class, :status) 
                ON CONFLICT (url) DO NOTHING";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            'url'    => $url,
            'class'   => $class,
            'status' => self::STATUS_PENDING
        ]);
    }

    /**
     * Взять одну задачу на выполнение (атомарно)
     */
    public function reserveNextTask(): ?array
    {
        $this->db->beginTransaction();

        try {
            $sql = "SELECT * FROM materials_query 
                    WHERE status = :status 
                    ORDER BY created_at ASC 
                    LIMIT 1 
                    FOR UPDATE SKIP LOCKED";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'status' => self::STATUS_PENDING,
            ]);
            $task = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($task) {
                $update = $this->db->prepare("UPDATE materials_query SET status = :status, updated_at = NOW() WHERE id = :id");
                $update->execute([
                    'status' => self::STATUS_PROCESSING, 'id' => $task['id'],
                ]);

                $this->db->commit();
                return $task;
            }

            $this->db->rollBack();
            return null;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function updateStatus(int $id, string $status, ?string $errorMessage = null): void
    {
        $sql = "UPDATE materials_query SET status = :status, updated_at = NOW()";
        $params = [
            'status' => $status,
            'id' => $id,
        ];

        if ($errorMessage) {
            $sql .= ", attempts = attempts + 1";
        }

        $sql .= " WHERE id = :id";
        $this->db->prepare($sql)->execute($params);
    }
}