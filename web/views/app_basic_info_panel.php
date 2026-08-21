<?php
$appBasicEntries = [];
foreach (is_array($entries ?? null) ? $entries : [] as $row) {
    $boat = (int)($row['lane_number'] ?? 0);
    if ($boat >= 1 && $boat <= 6) {
        $appBasicEntries[$boat] = $row;
    }
}

$appBasicResults = [];
foreach (is_array($results ?? null) ? $results : [] as $idx => $row) {
    $boat = (int)($row['boat'] ?? ($idx + 1));
    if ($boat >= 1 && $boat <= 6) {
        $appBasicResults[$boat] = $row;
    }
}

$appBasicTenji = [];
foreach (is_array($tenji_list ?? null) ? $tenji_list : [] as $row) {
    $boat = (int)($row['teiban'] ?? 0);
    if ($boat >= 1 && $boat <= 6) {
        $appBasicTenji[$boat] = $row;
    }
}

$appBasicCourseByBoat = [];
for ($boat = 1; $boat <= 6; $boat++) {
    $course = (int)($prediction_course_by_boat[$boat] ?? $boat);
    $appBasicCourseByBoat[$boat] = ($course >= 1 && $course <= 6) ? $course : $boat;
}

$appBasicEsc = static function ($value): string {
    if ($value === null || $value === '') {
        return '-';
    }
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};

$appBasicNum = static function ($value, int $digits = 2): string {
    return is_numeric($value) ? number_format((float)$value, $digits) : '-';
};

$appBasicPct = static function ($value, int $digits = 1, float $scale = 1.0): string {
    return is_numeric($value)
        ? number_format((float)$value * $scale, $digits) . '%'
        : '-';
};

$appBasicKimarite = static function (int $boat, string $period, string $rowLabel) use (
    $kimarite_data,
    $appBasicCourseByBoat
): string {
    $course = (int)($appBasicCourseByBoat[$boat] ?? $boat);
    $map = [
        '逃げ / 逃がし' => $course === 1 ? 'nige' : 'nogashi',
        '差され / 差し' => $course === 1 ? 'sasare' : 'sashi',
        '捲られ / 捲り' => $course === 1 ? 'makurare' : 'makuri',
        '捲られ差 / 捲り差し' => $course === 1 ? 'makurarezashi' : 'makurizashi',
    ];
    $key = $map[$rowLabel] ?? '';
    if ($key === '') {
        return '-';
    }

    $data = $kimarite_data[$course][$period] ?? [];
    if (!is_array($data) || !isset($data[$key]) || !is_numeric($data[$key])) {
        return '-';
    }

    $rate = (float)$data[$key];
    $n = (int)($data['_sample_n'] ?? 0);
    $count = (int)(($data['_counts'] ?? [])[$key] ?? 0);

    if ($n > 0) {
        return number_format($rate, 1) . '%<span class="app-basic-sample">(' . $count . '/' . $n . ')</span>';
    }
    return number_format($rate, 1) . '%';
};

$appBasicBoatHeader = static function (int $boat) use ($lane_colors, $appBasicCourseByBoat): string {
    $c = $lane_colors[$boat] ?? $lane_colors[1];
    $course = (int)($appBasicCourseByBoat[$boat] ?? $boat);
    return '<div class="app-basic-boat-title">' . $boat . '号艇</div>'
        . '<span class="app-basic-boat-badge" style="background:'
        . htmlspecialchars((string)$c['bg'], ENT_QUOTES, 'UTF-8')
        . ';color:' . htmlspecialchars((string)$c['text'], ENT_QUOTES, 'UTF-8')
        . ';border-color:' . htmlspecialchars((string)$c['border'], ENT_QUOTES, 'UTF-8')
        . ';">' . $boat . '号</span>'
        . '<div class="app-basic-course">' . $course . 'C</div>';
};

$appBasicRenderRow = static function (string $label, callable $valueFn, string $extraClass = ''): void {
    echo '<div class="app-basic-label ' . htmlspecialchars($extraClass, ENT_QUOTES, 'UTF-8') . '">' . $label . '</div>';
    for ($boat = 1; $boat <= 6; $boat++) {
        echo '<div class="app-basic-value ' . htmlspecialchars($extraClass, ENT_QUOTES, 'UTF-8') . '">';
        echo $valueFn($boat);
        echo '</div>';
    }
};
?>

<section class="app-card app-basic-card">
    <div class="app-basic-grid">
        <div class="app-basic-section">📋 出走表・取得情報</div>
        <div class="app-basic-label app-basic-head-label">艇</div>
        <?php for ($boat = 1; $boat <= 6; $boat++): ?>
            <div class="app-basic-value app-basic-head-cell"><?= $appBasicBoatHeader($boat) ?></div>
        <?php endfor; ?>

        <?php $appBasicRenderRow('選手名', static function (int $boat) use ($appBasicEntries, $appBasicEsc): string {
            return '<span class="app-basic-player-name">' . $appBasicEsc($appBasicEntries[$boat]['player_name'] ?? '-') . '</span>';
        }, 'app-basic-compact'); ?>

        <?php $appBasicRenderRow('級別', static function (int $boat) use ($appBasicEntries, $appBasicEsc): string {
            return $appBasicEsc($appBasicEntries[$boat]['class'] ?? '-');
        }, 'app-basic-compact'); ?>

        <?php $appBasicRenderRow('支部', static function (int $boat) use ($appBasicEntries, $appBasicEsc): string {
            return $appBasicEsc($appBasicEntries[$boat]['branch'] ?? '-');
        }, 'app-basic-compact'); ?>

        <?php $appBasicRenderRow('全国勝率', static function (int $boat) use ($appBasicEntries, $appBasicNum): string {
            return $appBasicNum($appBasicEntries[$boat]['national_win_rate'] ?? null, 2);
        }); ?>

        <?php $appBasicRenderRow('当地勝率', static function (int $boat) use ($appBasicEntries, $appBasicNum): string {
            return $appBasicNum($appBasicEntries[$boat]['local_win_rate'] ?? null, 2);
        }); ?>

        <?php $appBasicRenderRow('モータ2連率', static function (int $boat) use ($appBasicEntries, $appBasicNum): string {
            return $appBasicNum($appBasicEntries[$boat]['motor_exacta_rate'] ?? null, 2);
        }, 'app-basic-small-label'); ?>

        <?php $appBasicRenderRow('ボート2連率', static function (int $boat) use ($appBasicEntries, $appBasicNum): string {
            return $appBasicNum($appBasicEntries[$boat]['boat_exacta_rate'] ?? null, 2);
        }, 'app-basic-small-label'); ?>

        <?php $appBasicRenderRow('平均ST', static function (int $boat) use ($appBasicEntries, $appBasicNum): string {
            return $appBasicNum($appBasicEntries[$boat]['average_start'] ?? null, 2);
        }); ?>

        <div class="app-basic-section">⏱ 展示・取得情報</div>
        <?php $appBasicRenderRow('展示タイム', static function (int $boat) use ($appBasicTenji, $appBasicNum): string {
            return $appBasicNum($appBasicTenji[$boat]['exhibition'] ?? null, 2);
        }); ?>

        <?php $appBasicRenderRow('周回', static function (int $boat) use ($appBasicTenji, $appBasicNum): string {
            return $appBasicNum($appBasicTenji[$boat]['lap'] ?? null, 2);
        }); ?>

        <?php $appBasicRenderRow('周り足', static function (int $boat) use ($appBasicTenji, $appBasicNum): string {
            return $appBasicNum($appBasicTenji[$boat]['mawari'] ?? null, 2);
        }); ?>

        <?php $appBasicRenderRow('直線', static function (int $boat) use ($appBasicTenji, $appBasicNum): string {
            return $appBasicNum($appBasicTenji[$boat]['straight'] ?? null, 2);
        }); ?>

        <?php $appBasicRenderRow('展示ST', static function (int $boat) use ($appBasicTenji, $appBasicNum): string {
            return $appBasicNum($appBasicTenji[$boat]['st'] ?? null, 2);
        }); ?>
    </div>

    <div class="app-basic-status">基本情報は取得値のみ表示。加工・評価結果は「メイン情報」に集約します。</div>
</section>