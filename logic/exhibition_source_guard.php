<?php

/**
 * 競艇日和への接続異常時に、連続アクセスを止めるための簡易サーキットブレーカー。
 * Web（Apache）とcronのどちらからでも使えるよう、ホストごとの/tmpに状態を置く。
 */

const EXHIBITION_SOURCE_FAILURE_LIMIT = 2;
const EXHIBITION_SOURCE_COOLDOWN_SECONDS = 1800;

function exhibitionSourceGuardFile(): string
{
    $override = getenv('BOATRACE_EXHIBITION_GUARD_FILE');
    if (is_string($override) && $override !== '') {
        return $override;
    }

    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'boatrace_kyoteibiyori_circuit.json';
}

function exhibitionSourceGuardRead($handle): array
{
    rewind($handle);
    $raw = stream_get_contents($handle);
    $state = is_string($raw) ? json_decode($raw, true) : null;

    return is_array($state) ? $state : [];
}

function exhibitionSourceGuardWrite($handle, array $state): void
{
    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($handle);
}

function exhibitionSourceCooldownRemaining(): int
{
    $handle = @fopen(exhibitionSourceGuardFile(), 'c+');
    if ($handle === false) {
        // 状態ファイルを使えない環境でも、取得そのものは止めない。
        return 0;
    }

    try {
        if (!flock($handle, LOCK_SH)) {
            return 0;
        }
        $state = exhibitionSourceGuardRead($handle);
        $remaining = max(0, (int)($state['blocked_until'] ?? 0) - time());
        flock($handle, LOCK_UN);
        return $remaining;
    } finally {
        fclose($handle);
    }
}

/** @return array{failure_count:int, blocked_until:int, cooldown_remaining:int} */
function recordExhibitionSourceFailure(): array
{
    $handle = @fopen(exhibitionSourceGuardFile(), 'c+');
    if ($handle === false) {
        return ['failure_count' => 0, 'blocked_until' => 0, 'cooldown_remaining' => 0];
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            return ['failure_count' => 0, 'blocked_until' => 0, 'cooldown_remaining' => 0];
        }

        $now = time();
        $state = exhibitionSourceGuardRead($handle);
        $previousBlock = (int)($state['blocked_until'] ?? 0);
        // 保護時間が明けた後だけ連続失敗数をリセットする。
        // 未ブロックの1回目失敗（blocked_until=0）は次の失敗へ確実に引き継ぐ。
        $failureCount = ($previousBlock > 0 && $previousBlock <= $now)
            ? 0
            : (int)($state['failure_count'] ?? 0);
        $failureCount++;

        $blockedUntil = $failureCount >= EXHIBITION_SOURCE_FAILURE_LIMIT
            ? $now + EXHIBITION_SOURCE_COOLDOWN_SECONDS
            : 0;

        $state = [
            'failure_count' => $failureCount,
            'last_failure_at' => $now,
            'blocked_until' => $blockedUntil,
        ];
        exhibitionSourceGuardWrite($handle, $state);
        flock($handle, LOCK_UN);

        return [
            'failure_count' => $failureCount,
            'blocked_until' => $blockedUntil,
            'cooldown_remaining' => max(0, $blockedUntil - $now),
        ];
    } finally {
        fclose($handle);
    }
}

function recordExhibitionSourceSuccess(): void
{
    $handle = @fopen(exhibitionSourceGuardFile(), 'c+');
    if ($handle === false) {
        return;
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            return;
        }
        exhibitionSourceGuardWrite($handle, [
            'failure_count' => 0,
            'last_success_at' => time(),
            'blocked_until' => 0,
        ]);
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }
}

function exhibitionSourceCooldownMessage(int $remainingSeconds): string
{
    $minutes = max(1, (int)ceil($remainingSeconds / 60));
    return "競艇日和への接続保護中です。約{$minutes}分後に再試行してください。";
}
