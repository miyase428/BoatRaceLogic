<?php

declare(strict_types=1);

/**
 * レース詳細画面（PC / アプリ）の最終HTMLへ「TOPへ戻る」導線を差し込む。
 *
 * 既存Viewの計算・表示ロジックには触れず、最外側の出力バッファだけを加工する。
 * CLIやAPIでは動作しない。
 */
final class HomeBackLinkInjector
{
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered || PHP_SAPI === 'cli') {
            return;
        }

        $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
        if (!in_array($script, ['index.php', 'app.php'], true)) {
            return;
        }

        self::$registered = true;

        ob_start(static function (string $html) use ($script): string {
            if ($html === '' || stripos($html, '<html') === false) {
                return $html;
            }

            $date = trim((string)($_GET['date'] ?? date('Y-m-d')));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $date = date('Y-m-d');
            }

            $homeUrl = '/web/home.php?date=' . rawurlencode($date);
            $safeUrl = htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8');

            $style = <<<'HTML'
<style id="home-back-link-style">
.home-detail-header{display:flex;align-items:center;justify-content:space-between;gap:12px;border-bottom:2px solid var(--border,#d8cdbc);margin-bottom:14px;padding-bottom:12px}
.home-detail-header h1{margin:0!important;padding:0!important;border-bottom:0!important;min-width:0}
.home-back-link{display:inline-flex;align-items:center;justify-content:center;gap:4px;flex:0 0 auto;border:1px solid #b9c9d3;border-radius:999px;background:#f5fbfe;color:#0f7ab8;text-decoration:none;font-size:12px;font-weight:800;line-height:1;padding:7px 11px;white-space:nowrap;box-shadow:0 1px 3px rgba(60,72,83,.06)}
.home-back-link:hover{background:#e8f5fb;border-color:#8fbfd5;color:#0b679b}
.app-header .home-back-link{font-size:11px;padding:6px 9px;order:2}
.app-header .app-title{order:1}
.app-header .app-race-label{order:3}
@media(max-width:520px){.app-header{align-items:center}.app-header .home-back-link{padding:6px 8px;font-size:10px}.app-header .app-title{font-size:22px}}
</style>
HTML;

            if (strpos($html, '</head>') !== false && strpos($html, 'home-back-link-style') === false) {
                $html = str_replace('</head>', $style . "\n</head>", $html);
            }

            if ($script === 'index.php') {
                $target = '<h1>艇 BoatRace Analytics</h1>';
                $replacement = '<div class="home-detail-header">'
                    . $target
                    . '<a class="home-back-link" href="' . $safeUrl . '">← TOPへ</a>'
                    . '</div>';

                if (strpos($html, $target) !== false) {
                    $html = str_replace($target, $replacement, $html);
                }
            } else {
                $target = '<div class="app-race-label">';
                $replacement = '<a class="home-back-link" href="' . $safeUrl . '">← TOP</a>'
                    . "\n        " . $target;

                if (strpos($html, 'class="home-back-link"') === false && strpos($html, $target) !== false) {
                    $html = str_replace($target, $replacement, $html, $count);
                }
            }

            return $html;
        });
    }
}

HomeBackLinkInjector::register();
