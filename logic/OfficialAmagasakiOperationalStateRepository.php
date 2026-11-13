<?php

declare(strict_types=1);

/** Shared, host-independent control record for the AMG cut-over. */
final class PdoOfficialAmagasakiOperationalStateRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array{status:string,route_enabled:bool,automation_approved:bool,nightly_backfill_enabled:bool,retry_interval_seconds:?int} */
    public function load(): array
    {
        $table = $this->pdo->query("SELECT to_regclass('boat_race.official_exhibition_control_v1') IS NOT NULL");
        if ($table === false || !in_array($table->fetchColumn(), [true, 't', '1', 1], true)) {
            return ['status' => 'not_installed', 'route_enabled' => false, 'automation_approved' => false, 'nightly_backfill_enabled' => false, 'retry_interval_seconds' => null];
        }
        $column = $this->pdo->query("SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'boat_race' AND table_name = 'official_exhibition_control_v1' AND column_name = 'nightly_backfill_enabled')");
        if ($column === false || !in_array($column->fetchColumn(), [true, 't', '1', 1], true)) {
            return ['status' => 'invalid', 'route_enabled' => false, 'automation_approved' => false, 'nightly_backfill_enabled' => false, 'retry_interval_seconds' => null];
        }
        $statement = $this->pdo->query("SELECT route_enabled, automation_approved, nightly_backfill_enabled, retry_interval_seconds FROM boat_race.official_exhibition_control_v1 WHERE control_key = 'amagasaki'");
        $row = $statement === false ? false : $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return ['status' => 'invalid', 'route_enabled' => false, 'automation_approved' => false, 'nightly_backfill_enabled' => false, 'retry_interval_seconds' => null];
        $route = $this->boolean($row['route_enabled'] ?? null);
        $approved = $this->boolean($row['automation_approved'] ?? null);
        $nightly = $this->boolean($row['nightly_backfill_enabled'] ?? null);
        $interval = $row['retry_interval_seconds'] ?? null;
        if ($route === null || $approved === null || $nightly === null || ($interval !== null && (!is_numeric($interval) || (int)$interval < 0 || (int)$interval > 1800))) {
            return ['status' => 'invalid', 'route_enabled' => false, 'automation_approved' => false, 'nightly_backfill_enabled' => false, 'retry_interval_seconds' => null];
        }
        return ['status' => 'ok', 'route_enabled' => $route, 'automation_approved' => $approved, 'nightly_backfill_enabled' => $nightly, 'retry_interval_seconds' => $interval === null ? null : (int)$interval];
    }

    private function boolean(mixed $value): ?bool
    {
        if (in_array($value, [true, 't', 'true', '1', 1], true)) return true;
        if (in_array($value, [false, 'f', 'false', '0', 0], true)) return false;
        return null;
    }
}
