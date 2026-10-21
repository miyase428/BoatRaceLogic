<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/logic/IdentitySeparatedFormalCloseV3.php';

/**
 * Isolated CC27B fixture.  It copies the completed historical Window into a
 * temporary repository, so neither preflight nor commit tests can mutate the
 * production Window.  The fixture is intentionally filesystem-only.
 */
final class IdentitySeparatedFormalCloseV3Fixture
{
    private const WINDOW_ID = 'successor-v3-prepared-20261010215741-f5098693';

    /** @var list<string> */
    private array $failedArtifacts = [
        'provenance/implementation_manifests/068ac52f046a1807c5fb5d31f0201cb3ebc9de908c723a6d3193003452d5b647.json',
        'provenance/implementation_sources/068ac52f046a1807c5fb5d31f0201cb3ebc9de908c723a6d3193003452d5b647.json',
        'provenance/implementation_manifests/8c6cc5bcaa71b323beb88fff2de59edf2fa9feb4fb15596e51fb29b0391532e9.json',
        'provenance/implementation_sources/8c6cc5bcaa71b323beb88fff2de59edf2fa9feb4fb15596e51fb29b0391532e9.json',
    ];

    /** @var list<string> */
    private array $passed = [];

    public function __construct(private readonly string $sourceRoot) {}

    public function run(string $mode = 'all'): void
    {
        $repo = $this->makeRepository();
        try {
            $service = new IdentitySeparatedFormalCloseV3($repo);
            $runtime = $this->runtime($repo);
            $before = $this->treeFingerprint($repo . '/var/strict_forward_live_readiness/windows/' . self::WINDOW_ID);
            $first = $service->preflight(self::WINDOW_ID, $runtime, '2026-10-12T09:00:00+09:00', $this->failedArtifacts);
            $second = $mode === 'baseline' || $mode === 'all'
                ? $service->preflight(self::WINDOW_ID, $runtime, '2026-10-12T09:00:00+09:00', $this->failedArtifacts)
                : $first;
            $third = $mode === 'baseline' || $mode === 'all'
                ? $service->preflight(self::WINDOW_ID, $runtime, '2026-10-12T09:00:00+09:00', $this->failedArtifacts)
                : $first;
            $after = $this->treeFingerprint($repo . '/var/strict_forward_live_readiness/windows/' . self::WINDOW_ID);
            $this->expect(!empty($first['accepted']), 'historical capture identity differs from closing identity and remains close-eligible');
            $this->expect(($first['preflight']['capture']['identity'] ?? '') !== ($first['preflight']['closing_tool_measurement_identity'] ?? ''), 'capture and closing identities are explicitly separated');
            $this->expect($before === $after, 'three pure preflights write zero target Window files');
            $this->expect(($first['preflight']['preflight_token'] ?? '') === ($second['preflight']['preflight_token'] ?? '')
                && ($second['preflight']['preflight_token'] ?? '') === ($third['preflight']['preflight_token'] ?? ''), 'repeated preflight has one deterministic token');
            $this->expect(count((array)($first['preflight']['failed_close_artifacts'] ?? [])) === count($this->failedArtifacts), 'auxiliary provenance artifacts are explicitly excluded and hash-bound');

            if ($mode === 'baseline') {
                echo "PASS " . count($this->passed) . " identity-separated close v3 fixture checks\n";
                foreach ($this->passed as $name) echo " - {$name}\n";
                return;
            }

            if ($mode === 'declaration' || $mode === 'all') $this->expectBlocked($service, $repo, 'declaration mismatch', static function (string $root): void {
                self::mutateJson($root . '/var/strict_forward_live_readiness/windows/' . self::WINDOW_ID . '/declared_implementation_identity.json', static function (array $row): array { $row['implementation_identity'] = 'sha256:' . str_repeat('0', 64); return $row; });
            }, $runtime);
            if ($mode === 'activation' || $mode === 'all') $this->expectBlocked($service, $repo, 'activation mismatch', static function (string $root): void {
                self::mutateJson($root . '/var/strict_forward_live_readiness/windows/' . self::WINDOW_ID . '/activation_record.json', static function (array $row): array { $row['implementation_identity'] = 'sha256:' . str_repeat('1', 64); return $row; });
            }, $runtime);
            if ($mode === 'pointer' || $mode === 'all') $this->expectBlocked($service, $repo, 'pointer mismatch', static function (string $root): void {
                self::mutateJson($root . '/' . FormalMeasurementWindowContext::POINTER_RELATIVE_PATH, static function (array $row): array { $row['window_id'] = 'different-window'; return $row; });
            }, $runtime);
            if ($mode === 'abort' || $mode === 'all') $this->expectBlocked($service, $repo, 'abort exists', static function (string $root): void {
                $path = $root . '/var/strict_forward_live_readiness/lifecycle/aborts/' . self::WINDOW_ID . '.json';
                self::writeJson($path, ['schema_version' => FormalMeasurementWindowLifecycle::ABORT_SCHEMA_VERSION, 'window_id' => self::WINDOW_ID, 'abort_record_hash' => 'sha256:' . str_repeat('2', 64)]);
            }, $runtime);
            if ($mode === 'runtime' || $mode === 'all') {
                $enabled = $runtime; $enabled['scheduler_held'] = false;
                $this->expect(empty($service->preflight(self::WINDOW_ID, $enabled, '2026-10-12T09:00:00+09:00', $this->failedArtifacts)['accepted']), 'enabled scheduler blocks close');
                $running = $runtime; $running['capture_process_count'] = 1;
                $this->expect(empty($service->preflight(self::WINDOW_ID, $running, '2026-10-12T09:00:00+09:00', $this->failedArtifacts)['accepted']), 'running capture blocks close');
            }

            if (!in_array($mode, ['commit', 'all'], true)) {
                echo "PASS " . count($this->passed) . " identity-separated close v3 fixture checks\n";
                foreach ($this->passed as $name) echo " - {$name}\n";
                return;
            }

            $commit = $service->commit((array)$first['preflight'], static fn(): array => $runtime, '2026-10-12T09:01:00+09:00');
            $this->expect(($commit['status'] ?? '') === 'CLOSURE_CREATED', 'same verified payload creates exactly one external v3 closure');
            $this->expect(is_file($repo . '/var/strict_forward_live_readiness/lifecycle/closure_provenance/implementation_manifests/'
                . substr((string)$first['preflight']['closing_tool_measurement_identity'], 7) . '.json'), 'closing provenance is outside target Window');
            $repeat = $service->commit((array)$first['preflight'], static fn(): array => $runtime, '2026-10-12T09:02:00+09:00');
            $this->expect(($repeat['status'] ?? '') === 'CLOSURE_ALREADY_EXISTS', 'same closure payload is idempotent');
            $different = (array)$first['preflight']; $different['preflight_token'] = 'sha256:' . str_repeat('f', 64);
            $conflict = $service->commit($different, static fn(): array => $runtime, '2026-10-12T09:03:00+09:00');
            $this->expect(($conflict['reason'] ?? '') === 'BLOCKED_CLOSURE_CONFLICT', 'different closure payload conflicts');
            $lifecycle = new FormalMeasurementWindowLifecycle($repo);
            $state = $lifecycle->effectiveState(self::WINDOW_ID, $this->readJson($repo . '/var/strict_forward_live_readiness/windows/' . self::WINDOW_ID . '/validation_window_manifest.json'));
            $this->expect(($state['effective_state'] ?? '') === 'CLOSED', 'v3 closure activates WINDOW_CLOSED writer guard semantics');
        } finally {
            $this->removeTree($repo);
        }
        echo "PASS " . count($this->passed) . " identity-separated close v3 fixture checks\n";
        foreach ($this->passed as $name) echo " - {$name}\n";
    }

    /** @param callable(string):void $mutation @param array<string,mixed> $runtime */
    private function expectBlocked(IdentitySeparatedFormalCloseV3 $service, string $repo, string $name, callable $mutation, array $runtime): void
    {
        $copy = $this->makeRepository();
        try {
            $mutation($copy);
            $result = (new IdentitySeparatedFormalCloseV3($copy))->preflight(self::WINDOW_ID, $runtime + ['capture_lock_path' => $copy . '/capture.lock'], '2026-10-12T09:00:00+09:00', $this->failedArtifacts);
            $this->expect(empty($result['accepted']), $name . ' blocks close');
        } finally {
            $this->removeTree($copy);
        }
    }

    /** @return array{scheduler_held:bool,capture_process_count:int,scheduler_config_hash:string,capture_lock_path:string} */
    private function runtime(string $repo): array
    {
        return ['scheduler_held' => true, 'capture_process_count' => 0, 'scheduler_config_hash' => 'sha256:' . hash('sha256', 'fixture-held-cron'), 'capture_lock_path' => $repo . '/capture.lock'];
    }

    private function makeRepository(): string
    {
        $repo = sys_get_temp_dir() . '/boatrace_close_v3_' . bin2hex(random_bytes(8));
        $window = 'var/strict_forward_live_readiness/windows/' . self::WINDOW_ID;
        $this->copyTree($this->sourceRoot . '/' . $window, $repo . '/' . $window);
        $this->copyFile($this->sourceRoot . '/' . FormalMeasurementWindowContext::POINTER_RELATIVE_PATH, $repo . '/' . FormalMeasurementWindowContext::POINTER_RELATIVE_PATH);
        // The fixture evaluates the historical source explicitly.  It must not
        // inherit the production pointer, which legitimately targets a later
        // successor Window after the source has been closed.
        self::writeJson($repo . '/' . FormalMeasurementWindowContext::POINTER_RELATIVE_PATH, [
            'schema_version' => FormalMeasurementWindowContext::POINTER_SCHEMA_VERSION,
            'window_id' => self::WINDOW_ID,
            'window_root' => $window,
        ]);
        foreach (MeasurementImplementationProvenance::criticalPaths() as $relative) $this->copyFile($this->sourceRoot . '/' . $relative, $repo . '/' . $relative);
        return $repo;
    }

    private function copyTree(string $from, string $to): void
    {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $node) {
            $relative = substr($node->getPathname(), strlen($from));
            $target = $to . $relative;
            if ($node->isDir()) { if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) throw new RuntimeException('fixture_directory_create_failed'); }
            else $this->copyFile($node->getPathname(), $target);
        }
    }

    private function copyFile(string $from, string $to): void
    {
        if (!is_dir(dirname($to)) && !mkdir(dirname($to), 0775, true) && !is_dir(dirname($to))) throw new RuntimeException('fixture_directory_create_failed');
        if (!copy($from, $to)) throw new RuntimeException('fixture_copy_failed:' . $from);
    }

    /** @return array{count:int,hash:string} */
    private function treeFingerprint(string $root): array
    {
        $rows = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) if ($file instanceof SplFileInfo && $file->isFile()) {
            $relative = substr($file->getPathname(), strlen($root) + 1); $rows[] = $relative . ':' . hash_file('sha256', $file->getPathname());
        }
        sort($rows, SORT_STRING);
        return ['count' => count($rows), 'hash' => 'sha256:' . hash('sha256', implode("\n", $rows))];
    }

    /** @return array<string,mixed> */
    private function readJson(string $path): array { $row = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); return is_array($row) ? $row : []; }
    /** @param callable(array<string,mixed>):array<string,mixed> $change */
    private static function mutateJson(string $path, callable $change): void { self::writeJson($path, $change(json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR))); }
    /** @param array<string,mixed> $row */
    private static function writeJson(string $path, array $row): void { if (!is_dir(dirname($path))) mkdir(dirname($path), 0775, true); file_put_contents($path, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)); }
    private function expect(bool $condition, string $name): void { if (!$condition) throw new RuntimeException('fixture_assertion_failed:' . $name); $this->passed[] = $name; }
    private function removeTree(string $path): void { if (!is_dir($path)) return; foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $node) $node->isDir() ? rmdir($node->getPathname()) : unlink($node->getPathname()); rmdir($path); }
}

$mode = 'all';
foreach ($argv as $argument) if (str_starts_with($argument, '--mode=')) $mode = substr($argument, strlen('--mode='));
if (!in_array($mode, ['all', 'baseline', 'declaration', 'activation', 'pointer', 'abort', 'runtime', 'commit'], true)) {
    fwrite(STDERR, "invalid --mode\n"); exit(64);
}
(new IdentitySeparatedFormalCloseV3Fixture(dirname(__DIR__)))->run($mode);
