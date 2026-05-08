<?php
require __DIR__ . '/../vendor/autoload.php';
$p = new App\Services\ParserService();
$r = $p->parse('https://www.sostav.ru/publication/oflajn-novaya-roskosh-pochemu-ivent-marketing-stanovitsya-glavnym-aktivom-brenda-83463.html');
$md = $r['markdown'];
echo "len=" . strlen($md) . "\n";
echo "hashttp=" . (strpos($md, 'http') === false ? '0' : '1') . "\n";
echo "hasimg=" . (strpos($md, '![') === false ? '0' : '1') . "\n";
echo "hasesc=" . (strpos($md, '\\"') === false ? '0' : '1') . "\n";
