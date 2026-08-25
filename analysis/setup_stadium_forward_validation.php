<?php

declare(strict_types=1);

/**
 * 場特性の前向き実戦検証テーブルを作成する。
 *
 * Usage:
 *   php analysis/setup_stadium_forward_validation.php
 */

require_once __DIR__ . '/../common/db_connect.php';

$pdo = getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$table = 'boat_race.stadium_forward_validation';

$pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS {$table} (
    race_code        text PRIMARY KEY,
    race_date        date NOT NULL,
    place_code       varchar(3) NOT NULL,
    race_no          smallint NOT NULL,
    web_snapshot     jsonb NOT NULL DEFAULT '{}'::jsonb,
    decision_action  varchar(20) NOT NULL DEFAULT 'as_is',
    factors          jsonb NOT NULL DEFAULT '[]'::jsonb,
    final_head       smallint,
    final_bet        text NOT NULL DEFAULT '',
    decision_note    text NOT NULL DEFAULT '',
    actual_result    varchar(10),
    effect           varchar(20) NOT NULL DEFAULT 'pending',
    result_note      text NOT NULL DEFAULT '',
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now()
)
SQL);

$pdo->exec("CREATE INDEX IF NOT EXISTS idx_stadium_forward_validation_place_date ON {$table} (place_code, race_date DESC, race_code)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_stadium_forward_validation_effect ON {$table} (effect) WHERE effect <> 'pending'");

$count = (int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();

echo str_repeat('=', 64) . PHP_EOL;
echo '場特性 前向き実戦検証テーブル セットアップ完了' . PHP_EOL;
echo str_repeat('=', 64) . PHP_EOL;
echo "テーブル : {$table}" . PHP_EOL;
echo "既存記録 : {$count}件" . PHP_EOL;
echo '方式     : 1レース1行 / Web予想スナップショットは初回保存時に固定' . PHP_EOL;
