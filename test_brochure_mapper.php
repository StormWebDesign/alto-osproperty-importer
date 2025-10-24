<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/Mapper/BrochureMapper.php';

use AltoSync\Mapper\BrochureMapper;
use AltoSync\Logger;

if ($argc < 2) {
    echo "Usage: php test_brochure_mapper.php /path/to/property.xml\n";
    exit(1);
}

$xmlPath = $argv[1];
if (!file_exists($xmlPath)) {
    echo "File not found: $xmlPath\n";
    exit(1);
}

$xml = simplexml_load_file($xmlPath);
$result = BrochureMapper::extractPdfUrls($xml);

echo "PDF URLs detected:\n";
print_r($result);
