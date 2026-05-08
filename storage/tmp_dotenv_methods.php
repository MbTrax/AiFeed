<?php
require __DIR__ . '/../vendor/autoload.php';
$r = new ReflectionClass(Dotenv\Dotenv::class);
foreach ($r->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
  echo $m->getName(), "\n";
}
