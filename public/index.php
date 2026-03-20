<?php
require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$db = new \App\Database()->getConnection();


//$stmt = $db->query("SELECT * FROM items");
//$results = $stmt->fetchAll();
//
//
//var_dump($results);

$model = new App\Models\MaterialsQueue($db);
$model->enqueue('https://www.sostav.ru/publication/antikrizisnyj-piar-v-2026-godu-chto-vas-usilivaet-a-chto-sozdaet-novye-riski-82501.html', "\App\Parser");
//$parser = new \App\Parser();
//if ($parser->load('https://www.sostav.ru/publication/antikrizisnyj-piar-v-2026-godu-chto-vas-usilivaet-a-chto-sozdaet-novye-riski-82501.html')) {
//    echo $parser->markdown;
//}