<?php

/**
 * 展示進入で隣り合う艇の展示タイム差から、参考用の展示アラートを作る。
 *
 * 確率・買い目は変更しない。表示専用の展開判断材料。
 */
class ExhibitionAlertLogic
{
    private const STANDARD_GAP = 0.10;
    private const STRONG_GAP = 0.15;
    private const SUPER_GAP = 0.20;

    public function evaluate(array $tenjiList): array
    {
        $byCourse = [];
        foreach ($tenjiList as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $course = (int)($row['tenji_course'] ?? 0);
            $boat = (int)($row['teiban'] ?? ($index + 1));
            $time = $row['exhibition'] ?? null;
            if (
                $course < 1 || $course > 6
                || $boat < 1 || $boat > 6
                || isset($byCourse[$course])
                || !is_numeric($time)
            ) {
                continue;
            }
            $byCourse[$course] = [
                'course' => $course,
                'boat' => $boat,
                'time' => (float)$time,
            ];
        }

        if (count($byCourse) !== 6) {
            return [
                'status' => 'waiting',
                'alerts' => [],
                'main_alert' => null,
                'error' => '展示タイム待ち',
            ];
        }
        ksort($byCourse);

        $alerts = [];
        for ($innerCourse = 1; $innerCourse <= 5; $innerCourse++) {
            $outerCourse = $innerCourse + 1;
            $inner = $byCourse[$innerCourse];
            $outer = $byCourse[$outerCourse];
            $gap = abs($inner['time'] - $outer['time']);
            if ($gap + 1.0e-9 < self::STANDARD_GAP) {
                continue;
            }

            $innerFaster = $inner['time'] < $outer['time'];
            $faster = $innerFaster ? $inner : $outer;
            $slower = $innerFaster ? $outer : $inner;
            $direction = $innerFaster ? 'inner_faster' : 'outer_faster';
            $level = $this->levelFor($gap);
            $alerts[] = [
                'inner' => $inner,
                'outer' => $outer,
                'faster' => $faster,
                'slower' => $slower,
                'gap' => round($gap, 2),
                'direction' => $direction,
                'title' => $innerFaster ? '内優位' : '外攻め警戒',
                'summary' => $innerFaster
                    ? '内側の展示優位。内側を上げ、外側を少し警戒。'
                    : '外側の展示優位。外側の浮上と内側の信頼度低下を警戒。',
                'historical_effect' => $innerFaster
                    ? '検証: 優位艇の1着率 +5.46pt／外隣艇 -3.27pt'
                    : '検証: 優位艇の1着率 +6.32pt／内隣艇 -4.48pt',
                ...$level,
            ];
        }

        usort($alerts, static function (array $a, array $b): int {
            $severity = (int)$b['severity'] <=> (int)$a['severity'];
            if ($severity !== 0) {
                return $severity;
            }
            $gap = (float)$b['gap'] <=> (float)$a['gap'];
            if ($gap !== 0) {
                return $gap;
            }
            // 同差なら外攻めを先に表示し、イン飛び警戒を見逃しにくくする。
            return strcmp((string)$a['direction'], (string)$b['direction']);
        });

        return [
            'status' => 'ok',
            'alerts' => $alerts,
            'main_alert' => $alerts[0] ?? null,
            'error' => '',
        ];
    }

    private function levelFor(float $gap): array
    {
        if ($gap + 1.0e-9 >= self::SUPER_GAP) {
            return ['level' => '超展示アラート', 'severity' => 3, 'tone' => 'danger'];
        }
        if ($gap + 1.0e-9 >= self::STRONG_GAP) {
            return ['level' => '強展示アラート', 'severity' => 2, 'tone' => 'warning'];
        }
        return ['level' => '展示アラート', 'severity' => 1, 'tone' => 'standard'];
    }
}
