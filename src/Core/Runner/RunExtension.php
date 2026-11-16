<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\Mutant\ProvesBeforeRun;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * The extension a run's PHP loads beside its tests (ADR-0130, ADR-0147),
 * and what the settings ask of it there.
 *
 * @internal
 */
interface RunExtension
{
    /** Whether a stream that records its tests' lines records them with this extension. */
    public function recordsLines(): bool;

    /** The least steps a child may take past its parent's share where a stream counts steps; none otherwise. */
    public function stepFloor(): int|NotGiven;

    /** The prover of a run whose results are in this directory, where the settings ask for one. */
    public function prover(string $directory): ProvesBeforeRun|NotGiven;

    /**
     * The extension's path, which a stream loads, and what it watches there;
     * none where it watches nothing. It records lines only where the stream
     * records them.
     *
     * @return array{string, string}|NotGiven
     */
    public function forStreams(bool $recording): array|NotGiven;
}
