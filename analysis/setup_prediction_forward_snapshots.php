<?php

declare(strict_types=1);

/**
 * コースサイン・本命対抗の自動前向き検証テーブルを作成する。
 *
 * Usage:
 *   php analysis/setup_prediction_forward_snapshots.php
 */

require_once __DIR__ . '/../common/db_connect.php';

$pdo = getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS boat_race.prediction_forward_snapshots (
    id              bigserial PRIMARY KEY,
    race_code       text NOT NULL,
    race_date       date NOT NULL,
    place_code      varchar(3) NOT NULL,
    race_no         smallint NOT NULL,
    stage           varchar(20) NOT NULL,
    component       varchar(30) NOT NULL,
    validation_mode varchar(20) NOT NULL DEFAULT 'strict',
    snapshot_hash   char(64) NOT NULL,
    logic_version   varchar(64) NOT NULL DEFAULT '',
    payload         jsonb NOT NULL,
    captured_at     timestamptz NOT NULL DEFAULT now(),
    last_seen_at    timestamptz NOT NULL DEFAULT now(),
    actual_result   varchar(10),
    trifecta_payout integer,
    grade           jsonb,
    graded_at       timestamptz,
    CONSTRAINT prediction_forward_stage_chk
        CHECK (stage IN ('morning', 'exhibition')),
    CONSTRAINT prediction_forward_component_chk
        CHECK (component IN ('course_signals', 'prediction', 'hole_prediction')),
    CONSTRAINT prediction_forward_race_no_chk
        CHECK (race_no BETWEEN 1 AND 12),
    CONSTRAINT prediction_forward_validation_mode_chk
        CHECK (validation_mode IN ('strict', 'late_replay')),
    CONSTRAINT prediction_forward_snapshot_unique
        UNIQUE (race_code, stage, component, validation_mode, snapshot_hash)
)
SQL);

// 既存データはすべて締切前に保存された厳密スナップショットとして移行する。
$pdo->exec("ALTER TABLE boat_race.prediction_forward_snapshots ADD COLUMN IF NOT EXISTS validation_mode varchar(20) NOT NULL DEFAULT 'strict'");
$pdo->exec('ALTER TABLE boat_race.prediction_forward_snapshots DROP CONSTRAINT IF EXISTS prediction_forward_validation_mode_chk');
$pdo->exec(<<<'SQL'
ALTER TABLE boat_race.prediction_forward_snapshots
ADD CONSTRAINT prediction_forward_validation_mode_chk
CHECK (validation_mode IN ('strict', 'late_replay'))
SQL);

$pdo->exec('ALTER TABLE boat_race.prediction_forward_snapshots DROP CONSTRAINT IF EXISTS prediction_forward_snapshot_unique');
$pdo->exec(<<<'SQL'
ALTER TABLE boat_race.prediction_forward_snapshots
ADD CONSTRAINT prediction_forward_snapshot_unique
UNIQUE (race_code, stage, component, validation_mode, snapshot_hash)
SQL);

// 既存テーブルにも新しい大穴予想componentを適用する。
$pdo->exec('ALTER TABLE boat_race.prediction_forward_snapshots DROP CONSTRAINT IF EXISTS prediction_forward_component_chk');
$pdo->exec(<<<'SQL'
ALTER TABLE boat_race.prediction_forward_snapshots
ADD CONSTRAINT prediction_forward_component_chk
CHECK (component IN ('course_signals', 'prediction', 'hole_prediction'))
SQL);

$pdo->exec(<<<'SQL'
CREATE INDEX IF NOT EXISTS idx_prediction_forward_pending
ON boat_race.prediction_forward_snapshots (race_date, race_code)
WHERE graded_at IS NULL
SQL);

$pdo->exec('DROP INDEX IF EXISTS boat_race.idx_prediction_forward_report');
$pdo->exec(<<<'SQL'
CREATE INDEX IF NOT EXISTS idx_prediction_forward_report
ON boat_race.prediction_forward_snapshots (validation_mode, stage, component, race_date, race_code, captured_at DESC)
SQL);

$count = (int)$pdo->query('SELECT COUNT(*) FROM boat_race.prediction_forward_snapshots')->fetchColumn();

echo "自動前向き検証テーブルを準備しました\n";
echo "既存スナップショット: {$count}件\n";
