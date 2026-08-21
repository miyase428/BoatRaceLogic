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

$appBasicPeriod = static function (array $entry): string {
    foreach (['term', 'term_number', 'period', 'period_number', 'ki'] as $key) {
        if (!isset($entry[$key]) || $entry[$key] === '') {
            continue;
        }
        $value = trim((string)$entry[$key]);
        if ($value === '') {
            continue;
        }
        return str_ends_with($value, '期') ? $value : $value . '期';
    }
    return '-';
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
        <div class="app-basic-section">選手情報</div>
        <div class="app-basic-label app-basic-head-label">艇</div>
        <?php for ($boat = 1; $boat <= 6; $boat++): ?>
            <div class="app-basic-value app-basic-head-cell"><?= $appBasicBoatHeader($boat) ?></div>
        <?php endfor; ?>

        <?php $appBasicRenderRow('登録番号', static function (int $boat) use ($appBasicEntries, $appBasicEsc): string {
            return $appBasicEsc($appBasicEntries[$boat]['player_id'] ?? '-');
        }, 'app-basic-compact'); ?>

        <?php $appBasicRenderRow('選手名', static function (int $boat) use ($appBasicEntries, $appBasicEsc): string {
            return '<span class="app-basic-player-name">' . $appBasicEsc($appBasicEntries[$boat]['player_name'] ?? '-') . '</span>';
        }, 'app-basic-compact'); ?>

        <?php $appBasicRenderRow('級別', static function (int $boat) use ($appBasicEntries, $appBasicEsc): string {
            return $appBasicEsc($appBasicEntries[$boat]['class'] ?? '-');
        }, 'app-basic-compact'); ?>

        <?php $appBasicRenderRow('期', static function (int $boat) use ($appBasicEntries, $appBasicPeriod): string {
            return htmlspecialchars($appBasicPeriod($appBasicEntries[$boat] ?? []), ENT_QUOTES, 'UTF-8');
        }, 'app-basic-compact'); ?>

        <?php $appBasicRenderRow('支部', static function (int $boat) use ($appBasicEntries, $appBasicEsc): string {
            return $appBasicEsc($appBasicEntries[$boat]['branch'] ?? '-');
        }, 'app-basic-compact'); ?>

        <div class="app-basic-section">🎯 1着率</div>
        <?php $appBasicRenderRow('場1着率', static function (int $boat) use ($baseWinBoats, $appBasicPct): string {
            return $appBasicPct($baseWinBoats[$boat]['p0'] ?? null, 1, 100.0);
        }); ?>
        <?php $appBasicRenderRow('基本1着率', static function (int $boat) use ($baseWinBoats, $appBasicPct): string {
            return '<strong>' . $appBasicPct($baseWinBoats[$boat]['normalized_rate'] ?? null, 1) . '</strong>';
        }, 'app-basic-rate-blue'); ?>
        <?php $appBasicRenderRow('補正後1着率', static function (int $boat) use ($correctedWinBoats, $appBasicPct): string {
            $rate = $correctedWinBoats[(string)$boat]['corrected_rate'] ?? $correctedWinBoats[$boat]['corrected_rate'] ?? null;
            return '<strong>' . $appBasicPct($rate, 1) . '</strong>';
        }, 'app-basic-rate-gold'); ?>

        <div class="app-basic-section">🎯 1号艇1着時の2着率</div>
        <?php $appBasicRenderRow('場2着率', static function (int $boat) use ($head1SecondBoats, $appBasicPct): string {
            if ($boat === 1) return '-';
            return $appBasicPct($head1SecondBoats[$boat]['venue_rate'] ?? null, 1);
        }); ?>
        <?php $appBasicRenderRow('基本2着率', static function (int $boat) use ($head1SecondBoats, $appBasicPct): string {
            if ($boat === 1) return '-';
            return '<strong>' . $appBasicPct($head1SecondBoats[$boat]['basic_rate'] ?? null, 1) . '</strong>';
        }, 'app-basic-rate-purple'); ?>

        <div class="app-basic-section">🤖 AI3連対率</div>
        <?php $appBasicRenderRow('基礎3連対率', static function (int $boat) use ($aiTrioBoats, $appBasicPct): string {
            return $appBasicPct($aiTrioBoats[$boat]['base_rate'] ?? $aiTrioBoats[(string)$boat]['base_rate'] ?? null, 1);
        }); ?>
        <?php $appBasicRenderRow('AI3連対率', static function (int $boat) use ($aiTrioBoats, $appBasicPct): string {
            $row = $aiTrioBoats[$boat] ?? $aiTrioBoats[(string)$boat] ?? [];
            $rate = $row['ai_rate'] ?? null;
            $rank = (int)($row['ai_rank'] ?? 0);
            $rankHtml = $rank > 0 ? '<span class="app-basic-rank">AI ' . $rank . '位</span>' : '';
            return '<strong>' . $appBasicPct($rate, 1) . '</strong>' . $rankHtml;
        }, 'app-basic-rate-purple'); ?>

        <div class="app-basic-section">選手情報</div>
        <?php $appBasicRenderRow('全国勝率', static function (int $boat) use ($appBasicEntries, $appBasicNum): string {
            return $appBasicNum($appBasicEntries[$boat]['national_win_rate'] ?? null, 2);
        }); ?>
        <?php $appBasicRenderRow('当地勝率', static function (int $boat) use ($appBasicEntries, $appBasicNum): string {
            return $appBasicNum($appBasicEntries[$boat]['local_win_rate'] ?? null, 2);
        }); ?>
        <?php $appBasicRenderRow('平均ST', static function (int $boat) use ($appBasicEntries, $appBasicNum): string {
            return $appBasicNum($appBasicEntries[$boat]['average_start'] ?? null, 2);
        }); ?>
        <?php $appBasicRenderRow('一次評価', static function (int $boat) use ($appBasicResults, $appBasicEsc): string {
            return '<strong>' . $appBasicEsc($appBasicResults[$boat]['ichiji_eval'] ?? '-') . '</strong>';
        }, 'app-basic-eval'); ?>

        <div class="app-basic-section">⏱ 展示・評価情報</div>
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
        <?php $appBasicRenderRow('展示タイム\n場平均差', static function (int $boat) use ($appBasicTenji, $appBasicNum): string {
            return $appBasicNum($appBasicTenji[$boat]['ex_diff'] ?? null, 2);
        }, 'app-basic-small-label'); ?>
        <?php $appBasicRenderRow('展示タイプ名', static function (int $boat) use ($appBasicTenji, $appBasicEsc): string {
            return '<span class="app-basic-type">' . $appBasicEsc($appBasicTenji[$boat]['dtype'] ?? '-') . '</span>';
        }, 'app-basic-compact'); ?>
        <?php $appBasicRenderRow('最終二次\n予想スコア', static function (int $boat) use ($appBasicTenji): string {
            $v = $appBasicTenji[$boat]['final_2nd_score'] ?? null;
            return is_numeric($v) ? '<strong>' . number_format((float)$v, 0) . '</strong>' : '-';
        }, 'app-basic-score app-basic-small-label'); ?>

        <div class="app-basic-section">決まり手</div>
        <div class="app-basic-period">直近6ヶ月</div>
        <?php foreach (['逃げ / 逃がし', '差され / 差し', '捲られ / 捲り', '捲られ差 / 捲り差し'] as $label): ?>
            <?php $appBasicRenderRow($label, static function (int $boat) use ($appBasicKimarite, $label): string {
                return $appBasicKimarite($boat, '6month', $label);
            }, 'app-basic-kimarite'); ?>
        <?php endforeach; ?>

        <div class="app-basic-period">直近1年</div>
        <?php foreach (['逃げ / 逃がし', '差され / 差し', '捲られ / 捲り', '捲られ差 / 捲り差し'] as $label): ?>
            <?php $appBasicRenderRow($label, static function (int $boat) use ($appBasicKimarite, $label): string {
                return $appBasicKimarite($boat, '1year', $label);
            }, 'app-basic-kimarite'); ?>
        <?php endforeach; ?>
    </div>

    <?php if ($correctedWinStatus !== 'ok'): ?>
        <div class="app-basic-status">補正後1着率：<?= htmlspecialchars($correctedWinError ?: '展示情報待ち', ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
</section>
