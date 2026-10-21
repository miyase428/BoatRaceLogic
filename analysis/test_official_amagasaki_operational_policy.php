<?php

declare(strict_types=1);

require_once __DIR__ . '/../logic/OfficialAmagasakiLegacyWriteGuard.php';

final class GuardAuditFixture implements OfficialAmagasakiAuditStatusReader
{
    public bool $available = true;
    /** @var array<string,bool> */ public array $official = [];
    /** @var array<string,int> */ public array $complete = [];
    public function auditTableAvailable(): bool { return $this->available; }
    public function hasSavedOfficialAudit(string $raceCode): bool { return $this->official[$raceCode] ?? false; }
    public function completeRowCount(string $raceCode): int { return $this->complete[$raceCode] ?? 0; }
}
function policyAssert(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }

$cases = 0;
$audit = new GuardAuditFixture();
$guard = new OfficialAmagasakiLegacyWriteGuard(new OfficialAmagasakiOperationalPolicy(false, false), $audit);
policyAssert($guard->legacyWriteDecision('20261021AMG02', 'nightly')['allow_legacy'], 'route disabled preserves existing AMG legacy path'); $cases++;
policyAssert($guard->legacyWriteDecision('20261021TMG02', 'web')['allow_legacy'], 'other venues preserve legacy path'); $cases++;
policyAssert($guard->legacyWriteDecision('20261020AMG02', 'retry')['allow_legacy'], 'old AMG preserves legacy path'); $cases++;

$guard = new OfficialAmagasakiLegacyWriteGuard(new OfficialAmagasakiOperationalPolicy(true, false), $audit);
$result = $guard->legacyWriteDecision('20261021AMG02', 'web');
policyAssert(!$result['allow_legacy'] && $result['reason'] === 'OFFICIAL_AUTOMATION_NOT_APPROVED', 'explicit route without approval blocks rather than silently falling back'); $cases++;

$guard = new OfficialAmagasakiLegacyWriteGuard(new OfficialAmagasakiOperationalPolicy(true, true), $audit);
$result = $guard->legacyWriteDecision('20261021AMG02', 'nightly');
policyAssert(!$result['allow_legacy'] && $result['reason'] === 'OFFICIAL_DAYTIME_WORKER_OWNS_RACE', 'approved official route owns new empty AMG race'); $cases++;
$audit->complete['20261021AMG01'] = 6;
$result = $guard->legacyWriteDecision('20261021AMG01', 'retry');
policyAssert(!$result['allow_legacy'] && $result['reason'] === 'EXISTING_SOURCE_UNKNOWN_REQUIRES_REVIEW', 'existing source-unknown AMG rows are never relabeled or overwritten'); $cases++;
$audit->official['20261021AMG03'] = true;
$result = $guard->legacyWriteDecision('20261021AMG03', 'nightly');
policyAssert(!$result['allow_legacy'] && $result['reason'] === 'OFFICIAL_AUDITED_ROWS_PROTECTED', 'audited official rows are protected at legacy save boundary'); $cases++;
$audit->available = false;
$result = $guard->legacyWriteDecision('20261021AMG04', 'nightly');
policyAssert(!$result['allow_legacy'] && $result['reason'] === 'OFFICIAL_DAYTIME_WORKER_OWNS_RACE', 'missing audit migration never enables legacy overwrite after cut-over'); $cases++;

$root = realpath(__DIR__ . '/..');
$expectations = [
    'logic/scrape_exhibition.php' => ["officialAmagasakiLegacyWriteDecision(\$pdo, \$race_code, 'nightly')", "officialAmagasakiLegacyWriteDecision(\$pdo, \$raceCode, 'retry')"],
    'public/update_exhibition.php' => ["officialAmagasakiLegacyWriteDecision(\$pdo, \$race_code, 'web')"],
    'logic/retry_error_urls.php' => ["officialAmagasakiLegacyWriteDecision(\$pdo, \$canonicalRaceCode, 'retry')"],
    'analysis/collect_strict_forward_exhibition.php' => ["legacyWriteDecision(\$race, 'strict_forward')"],
];
foreach ($expectations as $path => $needles) {
    $source = (string)file_get_contents($root . '/' . $path);
    foreach ($needles as $needle) policyAssert(str_contains($source, $needle), $path . ' uses the AMG legacy-write guard at its route boundary');
}
$cases += 5;

echo "PASS {$cases} isolated operational policy cases\n";
