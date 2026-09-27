<?php
declare(strict_types=1);

// エア予想をサーバーに蓄積するための、一度だけ実行する作成スクリプト。
require_once __DIR__ . '/../common/db_connect.php';

$pdo = getPDO();
$sharedProfileId = 'b1e3d0b8-7939-4f1d-8d71-8f3c9d5d71a1';
$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS boat_race.air_predictions (
    id BIGSERIAL PRIMARY KEY,
    client_id UUID NOT NULL,
    profile_id UUID NOT NULL DEFAULT 'b1e3d0b8-7939-4f1d-8d71-8f3c9d5d71a1',
    race_code VARCHAR(13) NOT NULL,
    tickets JSONB NOT NULL DEFAULT '[]'::jsonb,
    stake INTEGER NOT NULL CHECK (stake BETWEEN 100 AND 100000),
    memo VARCHAR(120) NOT NULL DEFAULT '',
    result_combination VARCHAR(5),
    result_payout INTEGER CHECK (result_payout IS NULL OR result_payout >= 0),
    result_source VARCHAR(20),
    result_fetched_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT air_predictions_client_race_unique UNIQUE (client_id, race_code),
    CONSTRAINT air_predictions_profile_race_unique UNIQUE (profile_id, race_code),
    CONSTRAINT air_predictions_race_code_format CHECK (race_code ~ '^[0-9]{8}[A-Z]{3}(0[1-9]|1[0-2])$')
)
SQL);
$pdo->exec("ALTER TABLE boat_race.air_predictions ADD COLUMN IF NOT EXISTS ticket_input TEXT NOT NULL DEFAULT ''");
$pdo->exec("ALTER TABLE boat_race.air_predictions ADD COLUMN IF NOT EXISTS profile_id UUID");
$pdo->exec("UPDATE boat_race.air_predictions SET profile_id = '{$sharedProfileId}' WHERE profile_id IS NULL");
$pdo->exec("ALTER TABLE boat_race.air_predictions ALTER COLUMN profile_id SET DEFAULT '{$sharedProfileId}'");
$pdo->exec("ALTER TABLE boat_race.air_predictions ALTER COLUMN profile_id SET NOT NULL");
$pdo->exec(<<<'SQL'
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'air_predictions_profile_race_unique'
          AND conrelid = 'boat_race.air_predictions'::regclass
    ) THEN
        ALTER TABLE boat_race.air_predictions
            ADD CONSTRAINT air_predictions_profile_race_unique UNIQUE (profile_id, race_code);
    END IF;
END $$
SQL);
$pdo->exec('CREATE INDEX IF NOT EXISTS air_predictions_client_updated_idx ON boat_race.air_predictions (client_id, updated_at DESC)');
$pdo->exec('CREATE INDEX IF NOT EXISTS air_predictions_profile_updated_idx ON boat_race.air_predictions (profile_id, updated_at DESC)');
$pdo->exec('CREATE INDEX IF NOT EXISTS air_predictions_race_code_idx ON boat_race.air_predictions (race_code)');

echo "boat_race.air_predictions is ready\n";
