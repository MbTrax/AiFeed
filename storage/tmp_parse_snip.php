<?php
require __DIR__ . '/../vendor/autoload.php';
$p = new App\Services\ParserService();
$r = $p->parse('https://www.sostav.ru/publication/oflajn-novaya-roskosh-pochemu-ivent-marketing-stanovitsya-glavnym-aktivom-brenda-83463.html');
$md = $r['markdown'];
$snippet = substr($md, 0, 400);
echo $snippet, "\n";
echo "contains_quote=" . (strpos($md, '"') === false ? '0' : '1') . "\n";
echo "contains_esc_quote=" . (strpos($md, '\\"') === false ? '0' : '1') . "\n";
