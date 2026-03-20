<?php

namespace App;

use PDO;
use PDOException;

class Database {
    private $conn;

    public function getConnection() {
        $this->conn = null;

        // Извлекаем данные из переменных окружения
        $host = $_ENV['DB_HOST'];
        $port = $_ENV['DB_PORT'];
        $db_name = $_ENV['DB_NAME'];
        $username = $_ENV['DB_USER'];
        $password = $_ENV['DB_PASS'];

        try {
            // Формат DSN для PostgreSQL: pgsql:host=...;port=...;dbname=...
            $dsn = "pgsql:host=$host;port=$port;dbname=$db_name";

            $this->conn = new PDO($dsn, $username, $password);

            // Настройка режима ошибок
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        } catch(PDOException $exception) {
            echo "Ошибка подключения к PostgreSQL: " . $exception->getMessage();
        }

        return $this->conn;
    }

}