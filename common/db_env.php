<?php
declare(strict_types=1);

/**
 * リポジトリ直下の .env からDB接続設定を読む。
 * .env はGit管理対象外。
 */
function loadDbEnv(): array
{
    static $config = null;

    if (is_array($config)) {
        return $config;
    }

    $envPath = dirname(__DIR__) . '/.env';
    if (!is_file($envPath)) {
        throw new RuntimeException('.env が見つかりません: ' . $envPath);
    }

    $env = parse_ini_file($envPath, false, INI_SCANNER_RAW);
    if (!is_array($env)) {
        throw new RuntimeException('.env の読み込みに失敗しました');
    }

    foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $key) {
        if (!array_key_exists($key, $env) || trim((string)$env[$key]) === '') {
            throw new RuntimeException(".env の {$key} が未設定です");
        }
    }

    $port = trim((string)($env['DB_PORT'] ?? '5432'));
    if ($port === '' || !ctype_digit($port)) {
        throw new RuntimeException('.env の DB_PORT が不正です');
    }

    $config = [
        'host' => trim((string)$env['DB_HOST']),
        'port' => $port,
        'dbname' => trim((string)$env['DB_NAME']),
        'user' => trim((string)$env['DB_USER']),
        'password' => (string)$env['DB_PASSWORD'],
    ];

    return $config;
}
