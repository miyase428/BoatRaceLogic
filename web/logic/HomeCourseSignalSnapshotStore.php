<?php

declare(strict_types=1);

/**
 * TOP画面専用の「展示前・一次コースサイン」スナップショットを管理する。
 *
 * 当日画面の表示では、重い12か月集計を実行せず、この日付・場別の保存結果を
 * 読む。過去分は検証にも使えるよう6か月保持するが、TOPは表示対象日のものだけを
 * 使用する。
 */
final class HomeCourseSignalSnapshotStore
{
    private const SCHEMA_VERSION = 1;

    /** @return array<string,mixed>|null */
    public static function read(string $date): ?array
    {
        if (!self::isValidDate($date)) {
            return null;
        }

        $path = self::pathFor($date);
        if (!is_file($path)) {
            return null;
        }

        $decoded = json_decode((string)@file_get_contents($path), true);
        if (!is_array($decoded)
            || (int)($decoded['schema_version'] ?? 0) !== self::SCHEMA_VERSION
            || ($decoded['date'] ?? '') !== $date
            || empty($decoded['base_only'])
            || !is_array($decoded['places'] ?? null)
        ) {
            return null;
        }

        $configPath = dirname(__DIR__, 2) . '/config/course_signal_rules.json';
        $currentConfigMtime = (int)(@filemtime($configPath) ?: 0);
        if ((int)($decoded['config_mtime'] ?? -1) !== $currentConfigMtime) {
            return null;
        }

        return $decoded;
    }

    /**
     * @param array<string,array<string,mixed>> $places
     * @param array<string,array<int,string>> $raceCodes
     */
    public static function write(string $date, array $places, array $raceCodes): bool
    {
        if (!self::isValidDate($date) || $places === []) {
            return false;
        }

        $configPath = dirname(__DIR__, 2) . '/config/course_signal_rules.json';
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'date' => $date,
            'base_only' => true,
            'generated_at' => date('c'),
            'config_mtime' => (int)(@filemtime($configPath) ?: 0),
            'places' => $places,
            'race_codes' => $raceCodes,
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return false;
        }

        $dir = self::directory();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        $path = self::pathFor($date);
        $tmp = $path . '.' . getmypid() . '.tmp';
        $written = @file_put_contents($tmp, $json, LOCK_EX) !== false && @rename($tmp, $path);
        if (!$written) {
            @unlink($tmp);
            return false;
        }

        self::prune();
        return true;
    }

    private static function directory(): string
    {
        return dirname(__DIR__, 2) . '/var/cache/home_course_signals';
    }

    private static function pathFor(string $date): string
    {
        return self::directory() . '/' . str_replace('-', '', $date) . '.json';
    }

    private static function isValidDate(string $date): bool
    {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $dt !== false && $dt->format('Y-m-d') === $date;
    }

    private static function prune(): void
    {
        $cutoff = (new DateTimeImmutable('today'))->modify('-6 months')->format('Ymd');
        foreach (glob(self::directory() . '/*.json') ?: [] as $path) {
            $name = basename($path, '.json');
            if (preg_match('/^\d{8}$/', $name) && $name < $cutoff) {
                @unlink($path);
            }
        }
    }
}
