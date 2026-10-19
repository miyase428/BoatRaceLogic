<?php

declare(strict_types=1);

/**
 * Low-overhead, request-scoped timing for the app HTML path.
 *
 * Disabled by default. When enabled, it emits one structured JSON record to
 * the existing PHP error log and optionally a Server-Timing response header.
 */
final class AppPerformanceTiming
{
    private const LOG_PREFIX = 'boatrace_app_performance ';

    private static ?self $current = null;

    private bool $enabled;
    private bool $finished = false;
    private bool $serverTiming;
    private int $requestStartedNs = 0;
    /** @var array<string,int> */
    private array $started = [];
    /** @var array<string,float> */
    private array $metrics = [];
    /** @var array<string,string> */
    private array $states = [];
    private string $requestId = '';
    private string $route;
    private string $raceCode = '';

    private function __construct(string $route)
    {
        $config = self::loadConfig();
        $this->route = self::safeLabel($route, 'unknown');
        $this->serverTiming = self::normalizeConfigBoolean($config['server_timing'] ?? false);

        $enabled = self::normalizeConfigBoolean($config['enabled'] ?? false);
        $enabledOverride = getenv('BOATRACE_APP_PERF_TIMING');
        if ($enabledOverride !== false && $enabledOverride !== '') {
            $enabled = in_array(strtolower(trim($enabledOverride)), ['1', 'true', 'yes', 'on'], true);
        }

        $sampleRate = self::normalizeSampleRate($config['sample_rate'] ?? 0.05);
        $sampleOverride = getenv('BOATRACE_APP_PERF_SAMPLE_RATE');
        if ($sampleOverride !== false && $sampleOverride !== '') {
            $sampleRate = self::normalizeSampleRate($sampleOverride);
        }

        $this->enabled = $enabled && self::sampled($sampleRate);
        if (!$this->enabled) {
            return;
        }

        $this->requestStartedNs = hrtime(true);
        try {
            $this->requestId = bin2hex(random_bytes(8));
        } catch (Throwable) {
            $this->requestId = str_replace('.', '', uniqid('', true));
        }

        register_shutdown_function(static function (): void {
            self::$current?->finish();
        });
    }

    public static function startRequest(string $route): self
    {
        if (self::$current === null) {
            self::$current = new self($route);
        }
        return self::$current;
    }

    public static function current(): ?self
    {
        return self::$current?->enabled ? self::$current : null;
    }

    public function setRaceCode(string $raceCode): void
    {
        if (!$this->enabled) {
            return;
        }
        $raceCode = strtoupper(trim($raceCode));
        $this->raceCode = preg_match('/^\d{8}[A-Z]{3}\d{2}$/', $raceCode) ? $raceCode : '';
    }

    public function begin(string $name): void
    {
        if (!$this->enabled) {
            return;
        }
        $name = self::safeMetricName($name);
        if ($name !== '' && !isset($this->started[$name])) {
            $this->started[$name] = hrtime(true);
        }
    }

    public function end(string $name): void
    {
        if (!$this->enabled) {
            return;
        }
        $name = self::safeMetricName($name);
        if ($name === '' || !isset($this->started[$name])) {
            return;
        }
        $elapsed = (hrtime(true) - $this->started[$name]) / 1_000_000;
        unset($this->started[$name]);
        $this->metrics[$name] = round(($this->metrics[$name] ?? 0.0) + $elapsed, 3);
        $this->states[$name] = 'measured';
    }

    public function skipped(string $name, string $reason): void
    {
        if (!$this->enabled) {
            return;
        }
        $name = self::safeMetricName($name);
        if ($name !== '') {
            $this->states[$name] = 'skipped:' . self::safeLabel($reason, 'condition');
        }
    }

    public function finish(): void
    {
        if (!$this->enabled || $this->finished) {
            return;
        }
        $this->finished = true;

        try {
            $totalMs = round((hrtime(true) - $this->requestStartedNs) / 1_000_000, 3);
            $openMetrics = array_keys($this->started);
            foreach ($openMetrics as $name) {
                $this->states[$name] = 'incomplete';
            }

            $record = [
                'schema' => 'boatrace_app_performance_v1',
                'request_id' => $this->requestId,
                'route' => $this->route,
                'race_code' => $this->raceCode,
                'measured_at' => date(DATE_ATOM),
                'total_php_ms' => $totalMs,
                'metrics_ms' => $this->metrics,
                'metric_states' => $this->states,
                'missing_metrics' => $openMetrics,
            ];
            $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($json)) {
                error_log(self::LOG_PREFIX . $json);
            }

            if ($this->serverTiming && !headers_sent()) {
                $parts = ['app_total;dur=' . number_format($totalMs, 3, '.', '')];
                foreach ($this->metrics as $name => $duration) {
                    if (count($parts) >= 12) {
                        break;
                    }
                    $parts[] = str_replace('_', '-', $name) . ';dur=' . number_format($duration, 3, '.', '');
                }
                header('Server-Timing: ' . implode(', ', $parts), false);
                header('X-Boatrace-Performance-Request: ' . $this->requestId, false);
            }
        } catch (Throwable) {
            // Measurement must never affect prediction or response generation.
        }
    }

    /** @return array<string,mixed> */
    private static function loadConfig(): array
    {
        $path = __DIR__ . '/../../config/app_performance_timing.php';
        if (!is_file($path)) {
            return [];
        }
        try {
            $config = require $path;
            return is_array($config) ? $config : [];
        } catch (Throwable) {
            return [];
        }
    }

    private static function normalizeSampleRate(mixed $value): float
    {
        $rate = is_numeric($value) ? (float)$value : 0.0;
        return max(0.0, min(1.0, $rate));
    }

    private static function normalizeConfigBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                'true', '1' => true,
                'false', '0' => false,
                default => false,
            };
        }
        return false;
    }

    private static function sampled(float $sampleRate): bool
    {
        if ($sampleRate <= 0.0) {
            return false;
        }
        if ($sampleRate >= 1.0) {
            return true;
        }
        try {
            return random_int(1, 1_000_000) <= (int)round($sampleRate * 1_000_000);
        } catch (Throwable) {
            return false;
        }
    }

    private static function safeMetricName(string $name): string
    {
        $name = strtolower(trim($name));
        return preg_match('/^[a-z][a-z0-9_]{0,47}$/', $name) ? $name : '';
    }

    private static function safeLabel(string $value, string $fallback): string
    {
        $value = strtolower(trim($value));
        return preg_match('/^[a-z0-9_.-]{1,64}$/', $value) ? $value : $fallback;
    }
}
