<?php
namespace App\Services;

use PDO;
use Exception;

class DatabaseService {
    private ?PDO $pdo = null;
    private array $config;

    public function __construct(array $config) {
        $this->config = $config;
    }

    private function connect(): PDO {
        if ($this->pdo === null) {
            try {
                $host = $this->config['host'];
                $port = $this->config['port'];
                $dbname = $this->config['name'];

                $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";

                $this->pdo = new PDO($dsn, $this->config['user'], $this->config['pass'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
            } catch (Exception $e) {
                throw new Exception("Ошибка подключения к PostgreSQL: " . $e->getMessage());
            }
        }
        return $this->pdo;
    }

    public function query(string $sql, array $params = []) {
        $stmt = $this->connect()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function fetchOne(string $sql, array $params = []) {
        return $this->query($sql, $params)->fetch();
    }

    public function fetchAll(string $sql, array $params = []) {
        return $this->query($sql, $params)->fetchAll();
    }

    public function lastId() {
        return $this->connect()->lastInsertId();
    }
}