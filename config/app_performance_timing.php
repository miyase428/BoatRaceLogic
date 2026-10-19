<?php

declare(strict_types=1);

// Production measurement must be enabled only after review. These values can
// also be overridden by BOATRACE_APP_PERF_TIMING and
// BOATRACE_APP_PERF_SAMPLE_RATE without changing application code.
return [
    'enabled' => false,
    'sample_rate' => 0.05,
    'server_timing' => false,
];
