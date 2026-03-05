<?php
require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$db = new \App\Database()->getConnection();


$stmt = $db->query("SELECT * FROM items");
$results = $stmt->fetchAll();


var_dump($results);