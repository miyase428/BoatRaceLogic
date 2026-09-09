<?php
ob_start();
require __DIR__ . '/home.php';
$html = ob_get_clean();

$head = <<<'HTML'
    <link rel="manifest" href="/web/manifest.webmanifest">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="BoatRace">
HTML;

if (stripos($html, 'rel="manifest"') === false) {
    $html = str_replace('</head>', $head . "\n</head>", $html);
}

echo $html;
