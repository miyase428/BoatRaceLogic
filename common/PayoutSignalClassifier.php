<?php

declare(strict_types=1);

/**
 * 配当傾向サイン + 荒れ方タイプの共通判定。
 *
 * 2025-08-15〜2026-08-14で探索、2026-08-15〜08-31で候補確認、
 * 2026-09-01〜09-05で未使用前方確認した定義を、画面実装前の v1 候補として共通化する。
 *
 * 重要:
 * - ここでは「配当傾向」と「荒れ方」を別軸で返す。
 * - 率や閾値を画面側で再実装しない。TOP/レース詳細は必ずこのクラスを共有する。
 * - 希少Nの条件は observation 扱い。表示可否は呼び出し側で決めてもよい。
 * - このファイル作成時点では本番予想ロジックには補正を加えない。表示・検索用。
 *
 * 入力は point-in-time のレース前情報を正規化して渡す。
 * 必須キー:
 *   race_no             int
 *   nige                float  1号艇1年逃げ率(%)
 *   honmei_head         ?int   Web本命頭
 *   taikou_head         ?int   Web対抗頭
 *   makuri_max          float  2〜6号艇 1年捲り率最大(%)
 *   sashi_max           float  2〜6号艇 1年差し率最大(%)
 *   attack_max          float  2〜6号艇 1年(差し+捲り)最大(%)
 *   attack_gap          float  attack 1位-2位差(pt)
 *   attack_count20      int    attack>=20% の艇数
 *   outer_win_max       float  2〜6号艇 1年勝率最大(%)
 */
final class PayoutSignalClassifier
{
    public const VERSION = 'v1-candidate-20260906';

    /**
     * @return array{
     *   version:string,
     *   payout:array<string,array<string,mixed>>,
     *   chaos:array{primary:string,types:array<int,string>,reasons:array<int,string>}
     * }
     */
    public static function classify(array $r): array
    {
        $raceNo = (int)($r['race_no'] ?? 0);
        $nige = (float)($r['nige'] ?? 0.0);
        $honmei = self::nullableLane($r['honmei_head'] ?? null);
        $taikou = self::nullableLane($r['taikou_head'] ?? null);
        $makuriMax = (float)($r['makuri_max'] ?? 0.0);
        $sashiMax = (float)($r['sashi_max'] ?? 0.0);
        $attackMax = (float)($r['attack_max'] ?? 0.0);
        $attackGap = (float)($r['attack_gap'] ?? 999.0);
        $attackCount20 = (int)($r['attack_count20'] ?? 0);
        $outerWinMax = (float)($r['outer_win_max'] ?? 0.0);

        $webBothNon1 = $honmei !== null && $taikou !== null
            && $honmei !== 1 && $taikou !== 1;
        $early = $raceNo >= 1 && $raceNo <= 4;
        $late = $raceNo >= 9 && $raceNo <= 12;
        $strongAttack =
            ($attackMax >= 30.0 && $attackMax < 40.0)
            || ($makuriMax >= 15.0 && $makuriMax < 20.0)
            || ($sashiMax >= 25.0);

        $mediumSignals = [
            'イン逃げ50未満' => $nige < 50.0,
            'Web本命対抗とも非1' => $webBothNon1,
            '序盤1-4R' => $early,
            '捲り最大20-29' => $makuriMax >= 20.0 && $makuriMax < 30.0,
            // 未使用前方 N=7 のため観察継続。スコアには含めるが role=observation。
            '攻め20+が2艇' => $attackCount20 === 2,
        ];

        $mediumPairs = [
            'イン逃げ50未満 × Web本命対抗とも非1' =>
                $mediumSignals['イン逃げ50未満'] && $mediumSignals['Web本命対抗とも非1'],
            'イン逃げ50未満 × 序盤1-4R' =>
                $mediumSignals['イン逃げ50未満'] && $mediumSignals['序盤1-4R'],
            'Web本命対抗とも非1 × 序盤1-4R' =>
                $mediumSignals['Web本命対抗とも非1'] && $mediumSignals['序盤1-4R'],
            // Nは小さいため参考ペア。
            'イン逃げ50未満 × 捲り最大20-29' =>
                $mediumSignals['イン逃げ50未満'] && $mediumSignals['捲り最大20-29'],
        ];

        $highSignals = [
            'イン逃げ40未満' => $nige < 40.0,
            'Web本命対抗とも非1' => $webBothNon1,
            '序盤1-4R' => $early,
            '強い攻め兆候' => $strongAttack,
            '外コース勝率40以上' => $outerWinMax >= 40.0,
            '攻め上位差3pt未満' => $attackGap < 3.0,
        ];

        // 9/1〜9/5の未使用前方でも比較的安定したものを主候補にする。
        $highPairs = [
            'イン逃げ40未満 × 序盤1-4R' =>
                $highSignals['イン逃げ40未満'] && $highSignals['序盤1-4R'],
            'Web本命対抗とも非1 × 序盤1-4R' =>
                $highSignals['Web本命対抗とも非1'] && $highSignals['序盤1-4R'],
            'イン逃げ40未満 × Web本命対抗とも非1' =>
                $highSignals['イン逃げ40未満'] && $highSignals['Web本命対抗とも非1'],
            // 以下は観察。前方Nが小さい/再現が弱い。
            'Web本命対抗とも非1 × 強い攻め兆候' =>
                $highSignals['Web本命対抗とも非1'] && $highSignals['強い攻め兆候'],
        ];

        $bigSignals = [
            'イン逃げ60-69' => $nige >= 60.0 && $nige < 70.0,
            '後半9-12R' => $late,
            '捲り最大15-19' => $makuriMax >= 15.0 && $makuriMax < 20.0,
            '外コース勝率15未満' => $outerWinMax < 15.0,
            // 希少観察。
            '攻め20+が2艇' => $attackCount20 === 2,
        ];

        $bigPairs = [
            'イン逃げ60-69 × 捲り最大15-19' =>
                $bigSignals['イン逃げ60-69'] && $bigSignals['捲り最大15-19'],
            '後半9-12R × 捲り最大15-19' =>
                $bigSignals['後半9-12R'] && $bigSignals['捲り最大15-19'],
            'イン逃げ60-69 × 後半9-12R' =>
                $bigSignals['イン逃げ60-69'] && $bigSignals['後半9-12R'],
            '後半9-12R × 外コース勝率15未満' =>
                $bigSignals['後半9-12R'] && $bigSignals['外コース勝率15未満'],
            // 9/1〜9/5で再現しなかったため採用ペアには含めない。
        ];

        $mediumScore = self::score($mediumSignals);
        $highScore = self::score($highSignals);
        $bigScore = self::score($bigSignals);

        $mediumMatchedPairs = self::matched($mediumPairs);
        $highMatchedPairs = self::matched($highPairs);
        $bigMatchedPairs = self::matched($bigPairs);

        $payout = [
            'medium' => [
                'label' => '中配当',
                'range' => '5,000〜9,999円',
                'score' => $mediumScore,
                'max_score' => count($mediumSignals),
                'level' => $mediumScore >= 3 ? 'strong' : ($mediumScore >= 2 ? 'watch' : 'low'),
                'signals' => self::matched($mediumSignals),
                'pairs' => $mediumMatchedPairs,
                'roles' => [
                    'イン逃げ50未満' => 'stable_main',
                    'Web本命対抗とも非1' => 'amplifier',
                    '序盤1-4R' => 'amplifier',
                    '捲り最大20-29' => 'amplifier',
                    '攻め20+が2艇' => 'observation',
                ],
            ],
            'high' => [
                'label' => '高配当',
                'range' => '10,000〜19,999円',
                'score' => $highScore,
                'max_score' => count($highSignals),
                // 高配当はサイン総数より強ペアを優先。
                'level' => !empty($highMatchedPairs) ? 'strong' : ($highScore >= 2 ? 'watch' : 'low'),
                'signals' => self::matched($highSignals),
                'pairs' => $highMatchedPairs,
                'roles' => [
                    'イン逃げ40未満' => 'base_amplifier',
                    'Web本命対抗とも非1' => 'amplifier',
                    '序盤1-4R' => 'amplifier',
                    '強い攻め兆候' => 'amplifier',
                    '外コース勝率40以上' => 'rare_conditional',
                    '攻め上位差3pt未満' => 'support',
                ],
            ],
            'big' => [
                'label' => '大穴',
                'range' => '20,000円以上',
                'score' => $bigScore,
                'max_score' => count($bigSignals),
                // 大穴も強ペア優先。Score>=2は候補として再現。
                'level' => !empty($bigMatchedPairs) ? 'strong' : ($bigScore >= 2 ? 'watch' : 'low'),
                'signals' => self::matched($bigSignals),
                'pairs' => $bigMatchedPairs,
                'roles' => [
                    'イン逃げ60-69' => 'combination',
                    '後半9-12R' => 'combination',
                    '捲り最大15-19' => 'amplifier',
                    '外コース勝率15未満' => 'support',
                    '攻め20+が2艇' => 'observation',
                ],
            ],
        ];

        $types = [];
        $reasons = [];

        // イン崩壊型: 中配当の再現性が高く、非1頭率も大きく上がった構造。
        $inCollapse =
            ($nige < 50.0 && ($webBothNon1 || $early))
            || ($mediumScore >= 3);
        if ($inCollapse) {
            $types[] = 'イン崩壊';
            $reasons[] = '1号艇の逃げ弱さに別の崩れ要因が重なっている';
        }

        // ヒモ荒れ型: 大穴側で1号艇残りが多かった構造を優先。
        $himoChaos =
            ($late && ($nige >= 60.0 && $nige < 70.0))
            || ($late && $makuriMax >= 15.0 && $makuriMax < 20.0)
            || ($bigScore >= 2 && !$inCollapse);
        if ($himoChaos) {
            $types[] = 'ヒモ荒れ';
            $reasons[] = '1号艇頭を残しつつ2・3着が崩れる大穴構造に近い';
        }

        // 複合高配当型: 高配当は単独サインよりペア再現性を重視。
        $compoundHigh = !empty($highMatchedPairs);
        if ($compoundHigh) {
            $types[] = '複合高配当';
            $reasons[] = '高配当側で再現した複数要因のペアに該当';
        }

        $primary = '平常';
        if ($himoChaos && $payout['big']['level'] === 'strong') {
            $primary = 'ヒモ荒れ';
        } elseif ($inCollapse) {
            $primary = 'イン崩壊';
        } elseif ($compoundHigh) {
            $primary = '複合高配当';
        } elseif ($himoChaos) {
            $primary = 'ヒモ荒れ';
        }

        return [
            'version' => self::VERSION,
            'payout' => $payout,
            'chaos' => [
                'primary' => $primary,
                'types' => array_values(array_unique($types)),
                'reasons' => array_values(array_unique($reasons)),
            ],
        ];
    }

    private static function nullableLane(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        $n = (int)$v;
        return ($n >= 1 && $n <= 6) ? $n : null;
    }

    /** @param array<string,bool> $flags */
    private static function score(array $flags): int
    {
        $score = 0;
        foreach ($flags as $flag) {
            if ($flag) {
                $score++;
            }
        }
        return $score;
    }

    /** @param array<string,bool> $flags @return array<int,string> */
    private static function matched(array $flags): array
    {
        $out = [];
        foreach ($flags as $name => $flag) {
            if ($flag) {
                $out[] = $name;
            }
        }
        return $out;
    }
}
