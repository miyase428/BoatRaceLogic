<?php

require_once __DIR__ . '/../logic/race_url.php';

$race_code = $_GET['race_code'] ?? null;

if (!$race_code) {
    echo "race_code が必要です";
    exit;
}

try {
    $url = raceCodeToKyoteiBiyoriUrl($race_code);
    echo $url;
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}