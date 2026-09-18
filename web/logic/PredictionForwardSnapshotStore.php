<?php

declare(strict_types=1);

require_once __DIR__ . '/../../common/db_connect.php';

/**
 * 実際に画面へ出したコースサイン・本命対抗を、結果が出る前に固定保存する。
 *
 * UIの可用性を優先するため、公開用メソッドは例外を外へ投げない。
 * 同一内容はlast_seen_atだけを更新し、内容が変われば別スナップショットとして残す。
 */
final class PredictionForwardSnapshotStore
{
    private PDO $pdo;
    private ?bool $ready = null;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? getPDO();
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public static function captureDisplayedPrediction(array $viewData, string $source, ?PDO $pdo = null): void
    {
        try {
            (new self($pdo))->capturePrediction($viewData, $source, 'strict');
        } catch (Throwable $e) {
            error_log('prediction forward snapshot failed: ' . $e->getMessage());
        }
    }

    /** 23時取得の展示履歴から、結果を遮断して再現した本命・対抗を保存する。 */
    public static function captureLateReplayPrediction(array $viewData, ?PDO $pdo = null): void
    {
        try {
            (new self($pdo))->capturePrediction($viewData, 'nightly_late_replay', 'late_replay');
        } catch (Throwable $e) {
            error_log('prediction late replay snapshot failed: ' . $e->getMessage());
        }
    }

    public static function captureDisplayedCourseSignals(array $response, ?PDO $pdo = null): void
    {
        try {
            (new self($pdo))->captureCourseSignals($response, 'strict');
        } catch (Throwable $e) {
            error_log('course signal forward snapshot failed: ' . $e->getMessage());
        }
    }

    /** 23時取得の展示履歴から再現したコースサインを保存する。 */
    public static function captureLateReplayCourseSignals(array $response, ?PDO $pdo = null): void
    {
        try {
            (new self($pdo))->captureCourseSignals($response, 'late_replay');
        } catch (Throwable $e) {
            error_log('course signal late replay snapshot failed: ' . $e->getMessage());
        }
    }

    /**
     * 締切前に不変保存された大穴予想を、通常予想と同じ自動採点基盤へ登録する。
     */
    public static function captureFrozenHolePrediction(array $record, ?PDO $pdo = null): void
    {
        try {
            (new self($pdo))->captureHolePrediction($record, false);
        } catch (Throwable $e) {
            error_log('hole prediction forward snapshot failed: ' . $e->getMessage());
        }
    }

    /** 過去に締切前固定済みの不変JSONを初回移行する。 */
    public static function importFrozenHolePrediction(array $record, ?PDO $pdo = null): void
    {
        try {
            (new self($pdo))->captureHolePrediction($record, true);
        } catch (Throwable $e) {
            error_log('hole prediction forward import failed: ' . $e->getMessage());
        }
    }

    /** 23時取得の展示履歴から再現した大穴予想を保存する。 */
    public static function captureLateReplayHolePrediction(array $record, ?PDO $pdo = null): void
    {
        try {
            (new self($pdo))->captureHolePrediction($record, true, 'late_replay');
        } catch (Throwable $e) {
            error_log('hole prediction late replay snapshot failed: ' . $e->getMessage());
        }
    }

    public function isReady(): bool
    {
        if ($this->ready !== null) {
            return $this->ready;
        }
        $stmt = $this->pdo->query("SELECT to_regclass('boat_race.prediction_forward_snapshots')");
        return $this->ready = (string)$stmt->fetchColumn() !== '';
    }

    private function capturePrediction(array $viewData, string $source, string $validationMode): void
    {
        if (!$this->isReady() || !empty($viewData['simulation_active']) || empty($viewData['entry_map_ready'])) {
            return;
        }

        $raceCode = strtoupper(trim((string)($viewData['race_code'] ?? '')));
        $raceDate = trim((string)($viewData['selected_date'] ?? ''));
        $lateReplay = $validationMode === 'late_replay';
        if ((!$lateReplay && !$this->validCurrentRace($raceCode, $raceDate))
            || ($lateReplay && !$this->validRaceForDate($raceCode, $raceDate))
            || (!$lateReplay && !$this->isBeforeCachedOfficialDeadline($raceCode))
            || (!$lateReplay && $this->hasCompletedResult($raceCode))
            || ($lateReplay && $this->hasStrictSnapshot($raceCode, 'prediction'))) {
            return;
        }

        $honmeiHead = (int)($viewData['honmei_head'] ?? 0);
        $taikouHead = (int)($viewData['taikou_head'] ?? 0);
        $honmeiKai = trim((string)($viewData['honmei_kai'] ?? ''));
        $taikouKai = trim((string)($viewData['taikou_kai'] ?? ''));
        if ($honmeiHead < 1 || $honmeiHead > 6 || $taikouHead < 1 || $taikouHead > 6
            || self::expandTrifecta($honmeiKai) === [] || self::expandTrifecta($taikouKai) === []) {
            return;
        }

        $payload = [
            'source' => $source,
            'honmei_head' => $honmeiHead,
            'taikou_head' => $taikouHead,
            'honmei_kai' => $honmeiKai,
            'taikou_kai' => $taikouKai,
            'kiru_str' => (string)($viewData['kiru_str'] ?? ''),
            'rank_boats' => array_values(array_map('intval', (array)($viewData['rank_boats'] ?? []))),
            'prediction_entry_order' => (string)($viewData['prediction_entry_order'] ?? ''),
            'exhibition_entry_order' => (string)($viewData['exhibition_entry_order'] ?? ''),
            'lane1_escape_follower_applied' => !empty($viewData['lane1_escape_follower_applied']),
            'lane1_escape_follower_reason' => (string)($viewData['lane1_escape_follower_reason'] ?? ''),
            'validation_mode' => $validationMode,
            'result_cutoff' => $lateReplay ? 'before_target_race' : 'captured_before_deadline',
        ];

        $this->insertSnapshot(
            $raceCode,
            $raceDate,
            'exhibition',
            'prediction',
            $payload,
            self::predictionLogicVersion(),
            $validationMode
        );
    }

    private function captureCourseSignals(array $response, string $validationMode): void
    {
        if (!$this->isReady() || ($response['status'] ?? '') !== 'ok') {
            return;
        }

        $raceDate = trim((string)($response['date'] ?? ''));
        $phase = (string)($response['signal_phase'] ?? 'live');
        $stage = $phase === 'base' ? 'morning' : 'exhibition';
        $allRaceCodes = array_values(array_unique(array_map(
            static fn($v): string => strtoupper(trim((string)$v)),
            (array)($response['_snapshot_race_codes'] ?? [])
        )));
        $readyRaceCodes = $stage === 'morning'
            ? $allRaceCodes
            : array_values(array_unique(array_map(
                static fn($v): string => strtoupper(trim((string)$v)),
                (array)($response['_snapshot_ready_race_codes'] ?? [])
            )));

        $lateReplay = $validationMode === 'late_replay';
        if ($readyRaceCodes === [] || !$this->validDate($raceDate)
            || (!$lateReplay && $raceDate !== date('Y-m-d'))) {
            return;
        }

        $maps = [
            '1C逃げ' => (array)($response['lane1_matches'] ?? []),
            '2C差し' => (array)($response['lane2_sashi_matches'] ?? []),
            '2Cまくり' => (array)($response['lane2_makuri_matches'] ?? []),
            '3C攻め' => (array)($response['lane3_matches'] ?? []),
            '4C攻め' => (array)($response['matches'] ?? []),
            '5C攻め' => (array)($response['lane5_matches'] ?? []),
            '6C攻め' => (array)($response['lane6_matches'] ?? []),
        ];

        $byRace = array_fill_keys($readyRaceCodes, []);
        foreach ($maps as $type => $rows) {
            foreach ($rows as $raceCode => $detail) {
                $raceCode = strtoupper(trim((string)$raceCode));
                if (!array_key_exists($raceCode, $byRace) || !is_array($detail)) {
                    continue;
                }
                $byRace[$raceCode][] = [
                    'type' => $type,
                    'course' => (int)($detail['course'] ?? 0),
                    'player_id' => trim((string)($detail['player_id'] ?? '')),
                    'star_level' => (int)($detail['star_level'] ?? 1),
                    'signal' => (string)($detail['signal'] ?? ''),
                    'secondary_ready' => !empty($detail['secondary_ready']),
                    'detail' => $detail,
                ];
            }
        }

        $logicVersion = self::courseSignalLogicVersion();
        foreach ($byRace as $raceCode => $signals) {
            if ((!$lateReplay && !$this->validCurrentRace($raceCode, $raceDate))
                || ($lateReplay && !$this->validRaceForDate($raceCode, $raceDate))
                || (!$lateReplay && $stage === 'exhibition' && !$this->isBeforeCachedOfficialDeadline($raceCode))
                || (!$lateReplay && $this->hasCompletedResult($raceCode))
                || ($lateReplay && $this->hasStrictSnapshot($raceCode, 'course_signals'))) {
                continue;
            }
            usort($signals, static function (array $a, array $b): int {
                return [$a['course'], $a['type']] <=> [$b['course'], $b['type']];
            });
            $this->insertSnapshot(
                $raceCode,
                $raceDate,
                $stage,
                'course_signals',
                [
                    'phase' => $phase,
                    'place' => (string)($response['place'] ?? substr($raceCode, 8, 3)),
                    'signals' => $signals,
                    'no_signal' => $signals === [],
                    'validation_mode' => $validationMode,
                    'result_cutoff' => $lateReplay ? 'before_target_date' : 'captured_before_deadline',
                ],
                $logicVersion,
                $validationMode
            );
        }
    }

    private function captureHolePrediction(array $record, bool $allowHistorical, string $validationMode = 'strict'): void
    {
        if (!$this->isReady()) {
            return;
        }

        $raceCode = strtoupper(trim((string)($record['race_code'] ?? '')));
        $raceDate = trim((string)($record['target_date'] ?? ''));
        $sourceStage = strtolower(trim((string)($record['stage'] ?? '')));
        $stage = $sourceStage === 'provisional' ? 'morning' : $sourceStage;
        $validRace = $allowHistorical
            ? $this->validRaceForDate($raceCode, $raceDate)
            : $this->validCurrentRace($raceCode, $raceDate);
        if (!in_array($stage, ['morning', 'exhibition'], true)
            || !$validRace
            || (!$allowHistorical && $this->hasCompletedResult($raceCode))
            || ($validationMode === 'late_replay' && $this->hasStrictSnapshot($raceCode, 'hole_prediction'))) {
            return;
        }

        try {
            $snapshotAt = new DateTimeImmutable((string)($record['snapshot_at'] ?? ''));
            $deadlineAt = new DateTimeImmutable((string)($record['deadline_at'] ?? ''));
        } catch (Throwable) {
            return;
        }
        if ($validationMode === 'strict' && $snapshotAt >= $deadlineAt) {
            return;
        }

        $a = (array)($record['A'] ?? []);
        $b = (array)($record['B'] ?? []);
        if ((int)($a['boat'] ?? 0) < 1 || (int)($a['boat'] ?? 0) > 6
            || (int)($b['boat'] ?? 0) < 1 || (int)($b['boat'] ?? 0) > 6
            || !$this->validExplicitBets((array)($a['bets'] ?? []))
            || !$this->validExplicitBets((array)($b['bets'] ?? []))) {
            return;
        }

        $record['validation_mode'] = $validationMode;
        $record['result_cutoff'] = $validationMode === 'late_replay'
            ? 'before_target_race'
            : 'captured_before_deadline';

        $this->insertSnapshot(
            $raceCode,
            $raceDate,
            $stage,
            'hole_prediction',
            $record,
            (string)($record['logic_version'] ?? 'hole-forward'),
            $validationMode
        );
    }

    private function insertSnapshot(
        string $raceCode,
        string $raceDate,
        string $stage,
        string $component,
        array $payload,
        string $logicVersion,
        string $validationMode = 'strict'
    ): void {
        $canonical = self::canonicalize($payload);
        $json = json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return;
        }
        $hash = hash('sha256', $json);
        $raceNo = (int)substr($raceCode, 11, 2);
        $place = substr($raceCode, 8, 3);

        $stmt = $this->pdo->prepare(<<<'SQL'
INSERT INTO boat_race.prediction_forward_snapshots (
    race_code, race_date, place_code, race_no, stage, component,
    validation_mode, snapshot_hash, logic_version, payload, captured_at, last_seen_at
) VALUES (
    :race_code, :race_date, :place_code, :race_no, :stage, :component,
    :validation_mode, :snapshot_hash, :logic_version, CAST(:payload AS jsonb), NOW(), NOW()
)
ON CONFLICT (race_code, stage, component, validation_mode, snapshot_hash)
DO UPDATE SET last_seen_at = NOW()
SQL);
        $stmt->execute([
            ':race_code' => $raceCode,
            ':race_date' => $raceDate,
            ':place_code' => $place,
            ':race_no' => $raceNo,
            ':stage' => $stage,
            ':component' => $component,
            ':validation_mode' => $validationMode,
            ':snapshot_hash' => $hash,
            ':logic_version' => $logicVersion,
            ':payload' => $json,
        ]);
    }

    private function hasStrictSnapshot(string $raceCode, string $component): bool
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
SELECT EXISTS (
    SELECT 1
    FROM boat_race.prediction_forward_snapshots
    WHERE race_code = :race_code
      AND stage = 'exhibition'
      AND component = :component
      AND validation_mode = 'strict'
)
SQL);
        $stmt->execute([':race_code' => $raceCode, ':component' => $component]);
        return (bool)$stmt->fetchColumn();
    }

    private function hasCompletedResult(string $raceCode): bool
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
SELECT COUNT(DISTINCT NULLIF(regexp_replace(TRIM(rank::text), '[^0-9]', '', 'g'), '')::int)
FROM boat_race.race_result_detail
WHERE race_code = :race_code
  AND NULLIF(regexp_replace(TRIM(rank::text), '[^0-9]', '', 'g'), '')::int BETWEEN 1 AND 3
SQL);
        $stmt->execute([':race_code' => $raceCode]);
        return (int)$stmt->fetchColumn() >= 3;
    }

    private function isBeforeCachedOfficialDeadline(string $raceCode): bool
    {
        $date = substr($raceCode, 0, 8);
        $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'boatrace_official_deadlines'
            . DIRECTORY_SEPARATOR . 'deadlines_' . $date . '.json';
        if (!is_file($path)) {
            return false;
        }
        $data = json_decode((string)@file_get_contents($path), true);
        $time = is_array($data) ? ($data['deadlines'][$raceCode] ?? null) : null;
        if (!is_string($time) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
            return false;
        }
        $deadline = DateTimeImmutable::createFromFormat(
            '!Ymd H:i',
            $date . ' ' . $time,
            new DateTimeZone('Asia/Tokyo')
        );
        return $deadline !== false && new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')) < $deadline;
    }

    private function validExplicitBets(array $bets): bool
    {
        if ($bets === []) {
            return false;
        }
        foreach ($bets as $bet) {
            if (!is_string($bet) || preg_match('/^[1-6]-[1-6]-[1-6]$/', $bet) !== 1) {
                return false;
            }
            $parts = explode('-', $bet);
            if (count(array_unique($parts)) !== 3) {
                return false;
            }
        }
        return true;
    }

    private function validCurrentRace(string $raceCode, string $raceDate): bool
    {
        return $this->validRaceForDate($raceCode, $raceDate)
            && $raceDate === date('Y-m-d');
    }

    private function validRaceForDate(string $raceCode, string $raceDate): bool
    {
        return preg_match('/^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/', $raceCode) === 1
            && $this->validDate($raceDate)
            && substr($raceCode, 0, 8) === str_replace('-', '', $raceDate);
    }

    private function validDate(string $value): bool
    {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $dt !== false && $dt->format('Y-m-d') === $value;
    }

    public static function expandTrifecta(string $formation): array
    {
        $parts = explode('-', trim($formation));
        if (count($parts) !== 3) {
            return [];
        }
        $bets = [];
        foreach (str_split(trim($parts[0])) as $a) {
            foreach (str_split(trim($parts[1])) as $b) {
                foreach (str_split(trim($parts[2])) as $c) {
                    if (!preg_match('/^[1-6]$/', $a) || !preg_match('/^[1-6]$/', $b) || !preg_match('/^[1-6]$/', $c)) {
                        continue;
                    }
                    if ($a === $b || $a === $c || $b === $c) {
                        continue;
                    }
                    $bets[] = "{$a}-{$b}-{$c}";
                }
            }
        }
        return array_values(array_unique($bets));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }
        return $value;
    }

    private static function predictionLogicVersion(): string
    {
        return self::filesVersion([
            __DIR__ . '/PredictionLogic.php',
            __DIR__ . '/PredictionLogicProduction.php',
            __DIR__ . '/Lane1EscapeFollowerLogic.php',
            __DIR__ . '/../../config/lane1_escape_follower_model.php',
        ]);
    }

    private static function courseSignalLogicVersion(): string
    {
        return self::filesVersion([
            __DIR__ . '/../tamagawa_lane4_star_api.php',
            __DIR__ . '/../../config/course_signal_rules.json',
        ]);
    }

    private static function filesVersion(array $paths): string
    {
        $context = hash_init('sha256');
        foreach ($paths as $path) {
            hash_update($context, $path . "\n");
            if (is_file($path)) {
                hash_update_file($context, $path);
            }
        }
        return hash_final($context);
    }
}
