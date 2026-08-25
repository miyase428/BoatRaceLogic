<?php

declare(strict_types=1);

require_once __DIR__ . '/../../common/db_connect.php';

/**
 * 場特性の前向き実戦検証を1レース1行で保存する。
 *
 * 重要:
 * - web_snapshot は最初の保存時だけ記録し、その後の更新では上書きしない。
 * - 場特性は表示・判断材料として記録するだけで、PredictionLogicには接続しない。
 */
class ForwardValidationLogic
{
    public const FACTORS = [
        'basic' => '基本特性',
        'escape' => '逃げ時',
        'non1_outer' => 'イン飛び・外枠',
        'exhibition_st' => '展示・ST',
        'web_affinity' => 'Web相性',
    ];

    public const ACTIONS = [
        'as_is' => 'Web予想のまま',
        'adjust' => '場特性を見て変更',
        'skip' => '見送り',
    ];

    public const EFFECTS = [
        'pending' => '未判定',
        'improved' => '改善した',
        'same' => '変化なし',
        'worse' => '悪化した',
        'unclear' => '判定保留',
    ];

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? getPDO();
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function isReady(): bool
    {
        $stmt = $this->pdo->query("SELECT to_regclass('boat_race.stadium_forward_validation')");
        return (string)$stmt->fetchColumn() !== '';
    }

    public function load(string $raceCode): ?array
    {
        if (!$this->isReady()) {
            return null;
        }

        $stmt = $this->pdo->prepare(<<<SQL
SELECT
    race_code,
    race_date,
    place_code,
    race_no,
    web_snapshot,
    decision_action,
    factors,
    final_head,
    final_bet,
    decision_note,
    actual_result,
    effect,
    result_note,
    created_at,
    updated_at
FROM boat_race.stadium_forward_validation
WHERE race_code = :race_code
LIMIT 1
SQL);
        $stmt->execute([':race_code' => $raceCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $snapshot = json_decode((string)($row['web_snapshot'] ?? '{}'), true);
        $factors = json_decode((string)($row['factors'] ?? '[]'), true);
        $row['web_snapshot'] = is_array($snapshot) ? $snapshot : [];
        $row['factors'] = is_array($factors) ? array_values($factors) : [];
        return $row;
    }

    public function save(array $data): void
    {
        if (!$this->isReady()) {
            throw new RuntimeException('前向き検証テーブルが未作成です。');
        }

        $raceCode = strtoupper(trim((string)($data['race_code'] ?? '')));
        if (!preg_match('/^\d{8}[A-Z]{3}\d{2}$/', $raceCode)) {
            throw new InvalidArgumentException('race_code が不正です。');
        }

        $raceDate = trim((string)($data['race_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raceDate)) {
            throw new InvalidArgumentException('race_date が不正です。');
        }

        $placeCode = strtoupper(trim((string)($data['place_code'] ?? '')));
        if (!preg_match('/^[A-Z]{3}$/', $placeCode)) {
            throw new InvalidArgumentException('place_code が不正です。');
        }

        $raceNo = (int)($data['race_no'] ?? 0);
        if ($raceNo < 1 || $raceNo > 12) {
            throw new InvalidArgumentException('race_no が不正です。');
        }

        $action = (string)($data['decision_action'] ?? 'as_is');
        if (!isset(self::ACTIONS[$action])) {
            $action = 'as_is';
        }

        $effect = (string)($data['effect'] ?? 'pending');
        if (!isset(self::EFFECTS[$effect])) {
            $effect = 'pending';
        }

        $factors = [];
        foreach ((array)($data['factors'] ?? []) as $factor) {
            $factor = (string)$factor;
            if (isset(self::FACTORS[$factor]) && !in_array($factor, $factors, true)) {
                $factors[] = $factor;
            }
        }

        $finalHead = isset($data['final_head']) && $data['final_head'] !== ''
            ? (int)$data['final_head']
            : null;
        if ($finalHead !== null && ($finalHead < 1 || $finalHead > 6)) {
            $finalHead = null;
        }

        $actualResult = $this->normalizeActualResult((string)($data['actual_result'] ?? ''));
        if ($actualResult === null && trim((string)($data['actual_result'] ?? '')) !== '') {
            throw new InvalidArgumentException('実結果は 1-2-3 の形式で、異なる艇番を入力してください。');
        }

        $snapshot = is_array($data['web_snapshot'] ?? null) ? $data['web_snapshot'] : [];
        $snapshotJson = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $factorsJson = json_encode($factors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($snapshotJson) || !is_string($factorsJson)) {
            throw new RuntimeException('JSON変換に失敗しました。');
        }

        $stmt = $this->pdo->prepare(<<<SQL
INSERT INTO boat_race.stadium_forward_validation (
    race_code,
    race_date,
    place_code,
    race_no,
    web_snapshot,
    decision_action,
    factors,
    final_head,
    final_bet,
    decision_note,
    actual_result,
    effect,
    result_note,
    created_at,
    updated_at
) VALUES (
    :race_code,
    :race_date,
    :place_code,
    :race_no,
    CAST(:web_snapshot AS jsonb),
    :decision_action,
    CAST(:factors AS jsonb),
    :final_head,
    :final_bet,
    :decision_note,
    :actual_result,
    :effect,
    :result_note,
    NOW(),
    NOW()
)
ON CONFLICT (race_code) DO UPDATE SET
    decision_action = EXCLUDED.decision_action,
    factors = EXCLUDED.factors,
    final_head = EXCLUDED.final_head,
    final_bet = EXCLUDED.final_bet,
    decision_note = EXCLUDED.decision_note,
    actual_result = EXCLUDED.actual_result,
    effect = EXCLUDED.effect,
    result_note = EXCLUDED.result_note,
    updated_at = NOW()
SQL);

        $stmt->execute([
            ':race_code' => $raceCode,
            ':race_date' => $raceDate,
            ':place_code' => $placeCode,
            ':race_no' => $raceNo,
            ':web_snapshot' => $snapshotJson,
            ':decision_action' => $action,
            ':factors' => $factorsJson,
            ':final_head' => $finalHead,
            ':final_bet' => trim((string)($data['final_bet'] ?? '')),
            ':decision_note' => trim((string)($data['decision_note'] ?? '')),
            ':actual_result' => $actualResult,
            ':effect' => $effect,
            ':result_note' => trim((string)($data['result_note'] ?? '')),
        ]);
    }

    public function getPlaceStats(string $placeCode): array
    {
        $empty = [
            'total' => 0,
            'completed' => 0,
            'improved' => 0,
            'same' => 0,
            'worse' => 0,
            'unclear' => 0,
        ];

        if (!$this->isReady()) {
            return $empty;
        }

        $stmt = $this->pdo->prepare(<<<SQL
SELECT
    COUNT(*) AS total,
    COUNT(*) FILTER (WHERE actual_result IS NOT NULL AND actual_result <> '') AS completed,
    COUNT(*) FILTER (WHERE effect = 'improved') AS improved,
    COUNT(*) FILTER (WHERE effect = 'same') AS same,
    COUNT(*) FILTER (WHERE effect = 'worse') AS worse,
    COUNT(*) FILTER (WHERE effect = 'unclear') AS unclear
FROM boat_race.stadium_forward_validation
WHERE place_code = :place_code
SQL);
        $stmt->execute([':place_code' => strtoupper($placeCode)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        foreach ($empty as $key => $_value) {
            $empty[$key] = (int)($row[$key] ?? 0);
        }
        return $empty;
    }

    private function normalizeActualResult(string $value): ?string
    {
        $value = preg_replace('/\s+/', '', trim($value));
        if ($value === '') {
            return null;
        }
        if (!preg_match('/^([1-6])-([1-6])-([1-6])$/', $value, $m)) {
            return null;
        }
        $boats = [(int)$m[1], (int)$m[2], (int)$m[3]];
        if (count(array_unique($boats)) !== 3) {
            return null;
        }
        return implode('-', $boats);
    }
}
