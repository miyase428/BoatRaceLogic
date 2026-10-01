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

    /**
     * 締切前の公式3連単オッズと、その時点の本命・対抗買い目を固定保存する。
     * 表示用の/tmpキャッシュとは別の、検証用の永続記録。
     */
    public static function captureDisplayedTrifectaOdds(
        array $viewData,
        array $trifectaRows,
        array $oddsData,
        string $source,
        ?PDO $pdo = null
    ): void {
        try {
            (new self($pdo))->captureTrifectaOdds(
                $viewData,
                $trifectaRows,
                $oddsData,
                $source,
                false,
                false,
                'strict'
            );
        } catch (Throwable $e) {
            error_log('trifecta odds forward snapshot failed: ' . $e->getMessage());
        }
    }

    /** 公式に残る確定オッズを過去検証用として保存する。締切前記録とは混ぜない。 */
    public static function captureHistoricalTrifectaOdds(
        string $raceCode,
        string $raceDate,
        array $oddsData,
        string $source = 'official_historical_final',
        ?PDO $pdo = null
    ): void {
        try {
            (new self($pdo))->captureTrifectaOdds(
                ['race_code' => $raceCode, 'selected_date' => $raceDate],
                [],
                $oddsData,
                $source,
                true,
                false,
                'late_replay'
            );
        } catch (Throwable $e) {
            error_log('trifecta historical odds snapshot failed: ' . $e->getMessage());
        }
    }

    /**
     * ユーザーが当日画面で確認したオッズを、初回表示時刻で一度だけ保存する。
     * 画面表示そのものの取得頻度は変えず、検証用の記録だけを追加する。
     */
    public static function captureCurrentTrifectaOdds(
        string $raceCode,
        array $oddsData,
        array $trifectaRows = [],
        string $honmeiKai = '',
        string $taikouKai = '',
        string $source = 'user_display_v2',
        ?PDO $pdo = null,
        bool $holeAlert = false
    ): void {
        $raceCode = strtoupper(trim($raceCode));
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})[A-Z0-9]{3}(0[1-9]|1[0-2])$/', $raceCode, $m)) {
            return;
        }
        try {
            (new self($pdo))->captureTrifectaOdds(
                [
                    'race_code' => $raceCode,
                    'selected_date' => $m[1] . '-' . $m[2] . '-' . $m[3],
                    'honmei_kai' => $honmeiKai,
                    'taikou_kai' => $taikouKai,
                ],
                $trifectaRows,
                $oddsData,
                $source,
                false,
                $holeAlert,
                'strict'
            );
        } catch (Throwable $e) {
            error_log('trifecta current odds snapshot failed: ' . $e->getMessage());
        }
    }

    /**
     * ユーザーが当日画面で確認した2連単オッズを、初回表示時刻で一度だけ保存する。
     * 3連単と同じく、締切後や結果確定後の値は前方検証記録へ混ぜない。
     */
    public static function captureCurrentExactaOdds(
        string $raceCode,
        array $oddsData,
        array $exactaRows = [],
        string $source = 'user_display_v1',
        ?PDO $pdo = null
    ): void {
        $raceCode = strtoupper(trim($raceCode));
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})[A-Z0-9]{3}(0[1-9]|1[0-2])$/', $raceCode, $m)) {
            return;
        }
        try {
            (new self($pdo))->captureExactaOdds(
                [
                    'race_code' => $raceCode,
                    'selected_date' => $m[1] . '-' . $m[2] . '-' . $m[3],
                ],
                $exactaRows,
                $oddsData,
                $source,
                false,
                'strict'
            );
        } catch (Throwable $e) {
            error_log('exacta current odds snapshot failed: ' . $e->getMessage());
        }
    }

    /** 公式に残る確定2連単オッズを、締切前記録とは分けて保存する。 */
    public static function captureHistoricalExactaOdds(
        string $raceCode,
        string $raceDate,
        array $oddsData,
        string $source = 'official_historical_final',
        ?PDO $pdo = null
    ): void {
        try {
            (new self($pdo))->captureExactaOdds(
                ['race_code' => $raceCode, 'selected_date' => $raceDate],
                [],
                $oddsData,
                $source,
                true,
                'late_replay'
            );
        } catch (Throwable $e) {
            error_log('exacta historical odds snapshot failed: ' . $e->getMessage());
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
            // 8点型は既存買い目と独立した試験運用値。表示された時だけ同じ締切前
            // スナップショットに固定し、将来の型別的中率・回収率に使えるようにする。
            'eight_point_formation' => is_array($viewData['eight_point_formation'] ?? null)
                ? $viewData['eight_point_formation']
                : ['visible' => false],
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

    private function captureTrifectaOdds(
        array $viewData,
        array $trifectaRows,
        array $oddsData,
        string $source,
        bool $allowHistorical,
        bool $holeAlert,
        string $validationMode
    ): void {
        if (!$this->isReady()) {
            return;
        }

        $raceCode = strtoupper(trim((string)($viewData['race_code'] ?? '')));
        $raceDate = trim((string)($viewData['selected_date'] ?? ''));
        if (!$this->validRaceForDate($raceCode, $raceDate)
            || (!$allowHistorical && (!$this->validCurrentRace($raceCode, $raceDate)
                || !$this->isBeforeCachedOfficialDeadline($raceCode)
                || $this->hasCompletedResult($raceCode)))) {
            return;
        }

        // 通常表示は同じレースを何度開いても、最初に確認した時点だけを残す。
        // 手動更新で都合のよいオッズに差し替わるのを防ぐため。
        if (!$allowHistorical && $this->hasTrifectaOddsSource($raceCode, $source, $validationMode)) {
            return;
        }

        $odds = is_array($oddsData['odds'] ?? null) ? $oddsData['odds'] : [];
        $oddsCount = count($odds);
        if ((string)($oddsData['status'] ?? '') !== 'ok' || !in_array($oddsCount, [60, 120], true)) {
            return;
        }
        $cleanOdds = [];
        foreach ($odds as $combo => $oddsValue) {
            $combo = trim((string)$combo);
            $value = (float)$oddsValue;
            if (preg_match('/^[1-6]-[1-6]-[1-6]$/', $combo) === 1 && $value > 0.0) {
                $cleanOdds[$combo] = $value;
            }
        }
        ksort($cleanOdds, SORT_NATURAL);
        if (!in_array(count($cleanOdds), [60, 120], true)) {
            return;
        }

        $probabilities = self::trifectaProbabilityMap($trifectaRows);
        $honmei = self::expandTrifecta((string)($viewData['honmei_kai'] ?? ''));
        $taikou = self::expandTrifecta((string)($viewData['taikou_kai'] ?? ''));
        $combined = array_values(array_unique(array_merge($honmei, $taikou)));
        $payload = [
            'source' => $source,
            'odds_kind' => $allowHistorical ? 'official_final_historical' : 'official_pre_deadline',
            'official_odds_fetched_at' => (string)($oddsData['fetched_at'] ?? ''),
            'official_odds_cache_used' => !empty($oddsData['cache']['used']),
            'official_odds_source_url' => (string)($oddsData['source_url'] ?? ''),
            'odds' => $cleanOdds,
            'probabilities' => $probabilities,
            'honmei_kai' => (string)($viewData['honmei_kai'] ?? ''),
            'taikou_kai' => (string)($viewData['taikou_kai'] ?? ''),
            'selection_metrics' => [
                'honmei' => self::trifectaSelectionMetrics($honmei, $probabilities, $cleanOdds),
                'taikou' => self::trifectaSelectionMetrics($taikou, $probabilities, $cleanOdds),
                'combined' => self::trifectaSelectionMetrics($combined, $probabilities, $cleanOdds),
            ],
            'validation_mode' => $validationMode,
            'result_cutoff' => $allowHistorical ? 'official_historical_final_odds' : 'captured_before_deadline',
        ];

        // AI買い方タイプは、確率・オッズ・荒れ判定を同じ締切前時点で固定する。
        // 通常のオッズ表示スナップショットとはsourceを分け、画面閲覧順に左右されない。
        if ($source === 'ai_bet_modes_v1' && count($probabilities) === count($cleanOdds)) {
            $payload['ai_bet_modes'] = self::aiBetStrategyModes($probabilities, $cleanOdds, $holeAlert);
        }

        $this->insertSnapshot(
            $raceCode,
            $raceDate,
            'exhibition',
            'trifecta_odds',
            $payload,
            $source === 'ai_bet_modes_v1' ? 'ai-bet-modes-forward-v1' : 'trifecta-odds-forward-v1',
            $validationMode
        );
    }

    /** @param array<int,array<string,mixed>> $exactaRows */
    private function captureExactaOdds(
        array $viewData,
        array $exactaRows,
        array $oddsData,
        string $source,
        bool $allowHistorical,
        string $validationMode
    ): void {
        if (!$this->isReady()) {
            return;
        }

        $raceCode = strtoupper(trim((string)($viewData['race_code'] ?? '')));
        $raceDate = trim((string)($viewData['selected_date'] ?? ''));
        if (!$this->validRaceForDate($raceCode, $raceDate)
            || (!$allowHistorical && (!$this->validCurrentRace($raceCode, $raceDate)
                || !$this->isBeforeCachedOfficialDeadline($raceCode)
                || $this->hasCompletedResult($raceCode)))) {
            return;
        }
        if (!$allowHistorical && $this->hasExactaOddsSource($raceCode, $source, $validationMode)) {
            return;
        }

        $odds = is_array($oddsData['odds'] ?? null) ? $oddsData['odds'] : [];
        $oddsCount = count($odds);
        if ((string)($oddsData['status'] ?? '') !== 'ok' || !in_array($oddsCount, [20, 30], true)) {
            return;
        }
        $cleanOdds = [];
        foreach ($odds as $combo => $oddsValue) {
            $combo = trim((string)$combo);
            $value = (float)$oddsValue;
            [$first, $second] = array_pad(explode('-', $combo, 2), 2, '');
            if (preg_match('/^[1-6]$/', $first) === 1
                && preg_match('/^[1-6]$/', $second) === 1
                && $first !== $second && $value > 0.0) {
                $cleanOdds[$combo] = $value;
            }
        }
        ksort($cleanOdds, SORT_NATURAL);
        if (!in_array(count($cleanOdds), [20, 30], true)) {
            return;
        }

        $payload = [
            'source' => $source,
            'odds_kind' => $allowHistorical ? 'official_final_historical' : 'official_pre_deadline',
            'official_odds_fetched_at' => (string)($oddsData['fetched_at'] ?? ''),
            'official_odds_cache_used' => !empty($oddsData['cache']['used']),
            'official_odds_source_url' => (string)($oddsData['source_url'] ?? ''),
            'odds' => $cleanOdds,
            'probabilities' => self::exactaProbabilityMap($exactaRows),
            'validation_mode' => $validationMode,
            'result_cutoff' => $allowHistorical ? 'official_historical_final_odds' : 'captured_before_deadline',
        ];

        $this->insertSnapshot(
            $raceCode,
            $raceDate,
            'exhibition',
            'exacta_odds',
            $payload,
            'exacta-odds-forward-v1',
            $validationMode
        );
    }

    /** @return array<string,float> */
    private static function trifectaProbabilityMap(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $boats = array_values(array_map('intval', (array)($row['boats'] ?? [])));
            if (count($boats) !== 3 || count(array_unique($boats)) !== 3
                || min($boats) < 1 || max($boats) > 6) {
                continue;
            }
            $probability = (float)($row['probability'] ?? 0.0);
            if ($probability < 0.0 || $probability > 1.0) {
                continue;
            }
            $out[implode('-', $boats)] = $probability;
        }
        ksort($out, SORT_NATURAL);
        return $out;
    }

    /** @return array<string,float> */
    private static function exactaProbabilityMap(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $first = (int)($row['first'] ?? 0);
            $second = (int)($row['second'] ?? 0);
            $probability = (float)($row['probability'] ?? 0.0);
            if ($first < 1 || $first > 6 || $second < 1 || $second > 6 || $first === $second
                || $probability < 0.0 || $probability > 1.0) {
                continue;
            }
            $out[$first . '-' . $second] = $probability;
        }
        ksort($out, SORT_NATURAL);
        return $out;
    }

    private static function trifectaSelectionMetrics(array $bets, array $probabilities, array $odds): array
    {
        $bets = array_values(array_unique(array_filter($bets, static fn($v): bool => is_string($v) && $v !== '')));
        $probabilitySum = 0.0;
        $inverseOdds = 0.0;
        $modelReturnSum = 0.0;
        $oddsReady = $bets !== [];
        foreach ($bets as $bet) {
            $probabilitySum += (float)($probabilities[$bet] ?? 0.0);
            $value = (float)($odds[$bet] ?? 0.0);
            if ($value <= 0.0) {
                $oddsReady = false;
                continue;
            }
            $inverseOdds += 1.0 / $value;
            $modelReturnSum += (float)($probabilities[$bet] ?? 0.0) * $value;
        }
        return [
            'bets' => $bets,
            'points' => count($bets),
            'probability_sum' => $probabilitySum,
            'combined_odds' => $oddsReady && $inverseOdds > 0.0 ? 1.0 / $inverseOdds : null,
            // 各点を均等購入した場合の、モデル上の期待回収率。
            'equal_stake_model_expected_roi' => $oddsReady && $bets !== [] ? $modelReturnSum / count($bets) : null,
            'odds_ready' => $oddsReady,
        ];
    }

    /** @return array<string,mixed> */
    private static function aiBetStrategyModes(array $probabilities, array $odds, bool $holeAlert): array
    {
        $rows = [];
        foreach ($probabilities as $bet => $probability) {
            $value = (float)($odds[$bet] ?? 0.0);
            if ($value <= 0.0) {
                continue;
            }
            $rows[] = [
                'bet' => (string)$bet,
                'probability' => (float)$probability,
                'odds' => $value,
            ];
        }
        usort($rows, static function (array $a, array $b): int {
            $cmp = (float)$b['probability'] <=> (float)$a['probability'];
            return $cmp !== 0 ? $cmp : strcmp((string)$a['bet'], (string)$b['bet']);
        });

        $bets = static fn(array $selected): array => array_values(array_map(
            static fn(array $row): string => (string)$row['bet'],
            $selected
        ));
        $hitFocus = $bets(array_slice($rows, 0, 20));
        $balance = $bets(array_slice($rows, 0, 12));
        $top6Rows = array_slice($rows, 0, 6);
        $top6Mass = array_sum(array_map(
            static fn(array $row): float => (float)$row['probability'],
            $top6Rows
        ));
        $selective = $top6Mass >= 0.35 ? $bets($top6Rows) : [];

        $longshots = array_values(array_filter(
            $rows,
            static fn(array $row): bool => (float)$row['odds'] >= 100.0
        ));
        $aiManshuRate = array_sum(array_map(
            static fn(array $row): float => (float)$row['probability'],
            $longshots
        ));
        $oneShotRows = $holeAlert
            ? array_values(array_filter(
                $longshots,
                static fn(array $row): bool => (float)$row['probability'] >= 0.002
            ))
            : [];
        $oneShot = $bets(array_slice($oneShotRows, 0, 6));

        return [
            'version' => 'v1',
            'rules_frozen' => true,
            'hole_alert' => $holeAlert,
            'ai_manshu_probability' => $aiManshuRate,
            'modes' => [
                'HIT_FOCUS' => self::trifectaSelectionMetrics($hitFocus, $probabilities, $odds),
                'BALANCE' => self::trifectaSelectionMetrics($balance, $probabilities, $odds),
                'ONE_SHOT' => self::trifectaSelectionMetrics($oneShot, $probabilities, $odds),
                'SELECTIVE' => self::trifectaSelectionMetrics($selective, $probabilities, $odds),
            ],
            'selection_context' => [
                'top6_probability_mass' => $top6Mass,
                'selective_threshold' => 0.35,
                'one_shot_min_odds' => 100.0,
                'one_shot_min_probability' => 0.002,
            ],
        ];
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

    private function hasTrifectaOddsSource(string $raceCode, string $source, string $validationMode): bool
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
SELECT EXISTS (
    SELECT 1
    FROM boat_race.prediction_forward_snapshots
    WHERE race_code = :race_code
      AND stage = 'exhibition'
      AND component = 'trifecta_odds'
      AND validation_mode = :validation_mode
      AND payload->>'source' = :source
)
SQL);
        $stmt->execute([
            ':race_code' => $raceCode,
            ':validation_mode' => $validationMode,
            ':source' => $source,
        ]);
        return (bool)$stmt->fetchColumn();
    }

    private function hasExactaOddsSource(string $raceCode, string $source, string $validationMode): bool
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
SELECT EXISTS (
    SELECT 1
    FROM boat_race.prediction_forward_snapshots
    WHERE race_code = :race_code
      AND stage = 'exhibition'
      AND component = 'exacta_odds'
      AND validation_mode = :validation_mode
      AND payload->>'source' = :source
)
SQL);
        $stmt->execute([
            ':race_code' => $raceCode,
            ':validation_mode' => $validationMode,
            ':source' => $source,
        ]);
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
            __DIR__ . '/EightPointFormationLogic.php',
            __DIR__ . '/../../config/lane1_escape_follower_model.php',
        ]);
    }

    /**
     * コースサインの実装・モデルを識別する版。
     *
     * 前方保存とTOP展示前スナップショットで同じ版を使い、表示経路間で
     * 古いロジックの結果を混在させない。
     */
    public static function courseSignalLogicVersion(): string
    {
        return self::filesVersion([
            __DIR__ . '/../tamagawa_lane4_star_api.php',
            __DIR__ . '/TamagawaCenterSignalLogic.php',
            __DIR__ . '/../../forecast/tamagawa_center_signal_live_v1.py',
            __DIR__ . '/../../forecast/models/amagasaki_course_signal_v1.joblib',
            __DIR__ . '/../../forecast/models/ashiya_course_signal_v1.joblib',
            __DIR__ . '/../../forecast/models/biwako_course_signal_v1.joblib',
            __DIR__ . '/../../forecast/models/edogawa_course_signal_v1.joblib',
            __DIR__ . '/../../forecast/models/heiwajima_course_signal_v1.joblib',
            __DIR__ . '/../../forecast/models/mikuni_course_signal_v1.joblib',
            __DIR__ . '/../../forecast/models/tamagawa_center_signal_v1.joblib',
            __DIR__ . '/../../forecast/models/kiryuu_course_signal_v1.joblib',
            __DIR__ . '/../../forecast/models/toda_course_signal_v1.joblib',
            __DIR__ . '/../../forecast/models/omura_course_signal_v1.joblib',
            __DIR__ . '/../../forecast/models/shimonoseki_course_signal_v1.joblib',
            __DIR__ . '/../../forecast/models/suminoe_course_signal_v1.joblib',
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
