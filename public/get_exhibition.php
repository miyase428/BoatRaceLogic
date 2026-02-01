<?php
require_once __DIR__ . '/../logic/race_url.php';
require_once __DIR__ . '/../logic/scrape_exhibition.php';

header('Content-Type: application/json; charset=utf-8');

$race_code = $_GET['race_code'] ?? null;

if (!$race_code) {
    echo json_encode(['error' => 'race_code が必要です']);
    exit;
}

try {
    $url = raceCodeToKyoteiBiyoriUrl($race_code);
    $data = scrapeExhibitionData($url);

    echo json_encode([
        'race_code' => $race_code,
        'url'       => $url,
        'data'      => $data
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}