<?php

declare(strict_types=1);

/**
 * PayoutSignalClassifier に渡すレース前特徴量を、kimarite_api の返却値と
 * 現行Web予想summaryから共通生成する。
 *
 * 重要:
 * - 分析時と同じく直近1年を使用する。
 * - 1Cは sample_n>=10 を必須とする。
 * - 2〜6Cは sample_n>=10 かつ win/sashi/makuri が数値のコースだけ採用する。
 * - 外側は最低2コース有効であることを必須とする。
 * - 不足値を0扱いしてサインを誤発火させない。
 *
 * kimariteData は kimarite_api.php と同じ形式:
 *   [course]['1year']['_sample_n'|'nige'|'win'|'sashi'|'makuri' ...]
 */
final class PayoutSignalFeatureBuilder
{
    public const MIN_SAMPLE_N = 10;

    /**
     * @return array{
     *   status:string,
     *   reason:string,
     *   input:array<string,mixed>,
     *   meta:array<string,mixed>
     * }
     */
    public static function build(int $raceNo, array $kimariteData, array $summary = []): array
    {
        if ($raceNo < 1 || $raceNo > 12) {
            return self::waiting('レース番号が不正です');
        }

        $c1 = self::period($kimariteData, 1);
        $n1 = (int)($c1['_sample_n'] ?? 0);
        $nige = self::num($c1['nige'] ?? null);

        if ($n1 < self::MIN_SAMPLE_N || $nige === null) {
            return self::waiting('1コース逃げ率の母数不足または欠損', [
                'c1_sample_n' => $n1,
            ]);
        }

        $attacks = [];
        $sashis = [];
        $makuris = [];
        $wins = [];
        $sampleNs = [1 => $n1];
        $eligibleCourses = [];

        for ($course = 2; $course <= 6; $course++) {
            $p = self::period($kimariteData, $course);
            $n = (int)($p['_sample_n'] ?? 0);
            $sampleNs[$course] = $n;
            if ($n < self::MIN_SAMPLE_N) {
                continue;
            }

            $win = self::num($p['win'] ?? null);
            $sashi = self::num($p['sashi'] ?? null);
            $makuri = self::num($p['makuri'] ?? null);
            if ($win === null || $sashi === null || $makuri === null) {
                continue;
            }

            $attacks[$course] = $sashi + $makuri;
            $sashis[$course] = $sashi;
            $makuris[$course] = $makuri;
            $wins[$course] = $win;
            $eligibleCourses[] = $course;
        }

        if (count($attacks) < 2) {
            return self::waiting('2〜6コースの有効決まり手データが2コース未満', [
                'c1_sample_n' => $n1,
                'sample_n_by_course' => $sampleNs,
                'eligible_outer_courses' => $eligibleCourses,
            ]);
        }

        arsort($attacks, SORT_NUMERIC);
        $attackValues = array_values($attacks);
        $attackMax = (float)$attackValues[0];
        $attackSecond = (float)$attackValues[1];
        $attackGap = $attackMax - $attackSecond;

        $attackCount20 = 0;
        foreach ($attacks as $attack) {
            if ($attack >= 20.0) {
                $attackCount20++;
            }
        }

        $honmei = self::lane($summary['honmei_head'] ?? null);
        $taikou = self::lane($summary['taikou_head'] ?? null);

        return [
            'status' => 'ok',
            'reason' => '',
            'input' => [
                'race_no' => $raceNo,
                'nige' => $nige,
                'honmei_head' => $honmei,
                'taikou_head' => $taikou,
                'makuri_max' => max($makuris),
                'sashi_max' => max($sashis),
                'attack_max' => $attackMax,
                'attack_gap' => $attackGap,
                'attack_count20' => $attackCount20,
                'outer_win_max' => max($wins),
            ],
            'meta' => [
                'period' => '1year',
                'min_sample_n' => self::MIN_SAMPLE_N,
                'c1_sample_n' => $n1,
                'sample_n_by_course' => $sampleNs,
                'eligible_outer_courses' => $eligibleCourses,
                'attack_by_course' => $attacks,
            ],
        ];
    }

    private static function period(array $data, int $course): array
    {
        $row = $data[$course] ?? $data[(string)$course] ?? [];
        if (!is_array($row)) {
            return [];
        }
        $period = $row['1year'] ?? [];
        return is_array($period) ? $period : [];
    }

    private static function num(mixed $value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        return (float)$value;
    }

    private static function lane(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $lane = (int)$value;
        return ($lane >= 1 && $lane <= 6) ? $lane : null;
    }

    private static function waiting(string $reason, array $meta = []): array
    {
        return [
            'status' => 'waiting',
            'reason' => $reason,
            'input' => [],
            'meta' => $meta,
        ];
    }
}
