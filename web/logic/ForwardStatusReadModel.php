<?php

declare(strict_types=1);

require_once __DIR__ . '/FormalMeasurementWindowStatusInspector.php';

/**
 * Read-only projection for the TOP UI.
 *
 * The inspector intentionally has no route to formal write authorization or
 * provenance freezing.  A browser GET must therefore be observational only.
 */
final class ForwardStatusReadModel
{
    public function __construct(private readonly string $repoRoot)
    {
    }

    /** @return array<string,mixed> */
    public function current(): array
    {
        return (new FormalMeasurementWindowStatusInspector($this->repoRoot))->inspect();
    }
}
