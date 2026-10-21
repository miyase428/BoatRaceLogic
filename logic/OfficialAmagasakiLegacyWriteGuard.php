<?php

declare(strict_types=1);

require_once __DIR__ . '/OfficialAmagasakiOperationalPolicy.php';

interface OfficialAmagasakiAuditStatusReader
{
    public function auditTableAvailable(): bool;
    public function hasSavedOfficialAudit(string $raceCode): bool;
    /** @return int Number of complete legacy rows, never inferred from dates. */
    public function completeRowCount(string $raceCode): int;
}

/**
 * This guard is used by legacy entry points immediately before fetching and
 * immediately before saving.  It never identifies an old row as official.
 */
final class OfficialAmagasakiLegacyWriteGuard
{
    public function __construct(
        private readonly OfficialAmagasakiOperationalPolicy $policy,
        private readonly OfficialAmagasakiAuditStatusReader $audit,
    ) {
    }

    /** @return array{allow_legacy:bool,reason:string,mode:string} */
    public function legacyWriteDecision(string $raceCode, string $entry): array
    {
        $decision = $this->policy->decide($raceCode, $entry);
        // An audited official result remains protected even if a later
        // operator turns off the route flag during incident handling.
        if ($this->audit->auditTableAvailable() && $this->audit->hasSavedOfficialAudit($raceCode)) {
            return ['allow_legacy' => false, 'reason' => 'OFFICIAL_AUDITED_ROWS_PROTECTED', 'mode' => 'protected'];
        }
        if ($decision['mode'] === 'legacy') return ['allow_legacy' => true, 'reason' => $decision['reason'], 'mode' => 'legacy'];
        if ($decision['mode'] === 'blocked') {
            return ['allow_legacy' => false, 'reason' => $decision['reason'], 'mode' => 'blocked'];
        }
        if ($this->audit->completeRowCount($raceCode) >= 6) {
            return ['allow_legacy' => false, 'reason' => 'EXISTING_SOURCE_UNKNOWN_REQUIRES_REVIEW', 'mode' => 'review'];
        }
        return ['allow_legacy' => false, 'reason' => 'OFFICIAL_DAYTIME_WORKER_OWNS_RACE', 'mode' => 'official'];
    }
}

/** @return array{allow_legacy:bool,reason:string,mode:string} */
function officialAmagasakiLegacyWriteDecision(PDO $pdo, string $raceCode, string $entry): array
{
    // Do not cache the policy: this function is also called at the write
    // boundary, after a potentially long legacy fetch.
    return (new OfficialAmagasakiLegacyWriteGuard(
        OfficialAmagasakiOperationalPolicy::fromPdo($pdo),
        new PdoOfficialAmagasakiAuditStatusReader($pdo),
    ))->legacyWriteDecision($raceCode, $entry);
}

final class PdoOfficialAmagasakiAuditStatusReader implements OfficialAmagasakiAuditStatusReader
{
    private ?bool $auditTableAvailable = null;

    public function __construct(private readonly PDO $pdo) {}

    public function auditTableAvailable(): bool
    {
        if ($this->auditTableAvailable !== null) return $this->auditTableAvailable;
        $statement = $this->pdo->query("SELECT to_regclass('boat_race.exhibition_source_audit_v1') IS NOT NULL");
        return $this->auditTableAvailable = $statement !== false && in_array($statement->fetchColumn(), [true, 't', '1', 1], true);
    }

    public function hasSavedOfficialAudit(string $raceCode): bool
    {
        if (!$this->auditTableAvailable()) return false;
        $statement = $this->pdo->prepare("SELECT EXISTS (SELECT 1 FROM boat_race.exhibition_source_audit_v1 WHERE race_code = :race_code AND source = 'official_amagasaki' AND result_status = 'saved')");
        $statement->execute([':race_code' => $raceCode]);
        return in_array($statement->fetchColumn(), [true, 't', '1', 1], true);
    }

    public function completeRowCount(string $raceCode): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(DISTINCT entry_course) FROM boat_race.exhibition_live WHERE race_code = :race_code AND player_id IS NOT NULL AND exhibition_time > 0 AND start_timing IS NOT NULL AND lap_time > 0 AND around_time > 0');
        $statement->execute([':race_code' => $raceCode]);
        return (int)$statement->fetchColumn();
    }
}
