<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Fakes;

use function array_shift;

use SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData;
use SebastianBergmann\CodeCoverage\Driver\Driver;

/**
 * A coverage driver that reports at each stop the lines it was handed for
 * it, and collects nothing itself, so a test of a coverage run leaves the
 * suite's own collection alone.
 */
final class CoverageDriverFake extends Driver
{
    /** @param list<array<non-empty-string, array<int<1, max>, int>>> $stops the lines each stop reports, by file */
    public function __construct(private array $stops)
    {
    }

    public function name(): string
    {
        return 'fake';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function start(): void
    {
    }

    public function stop(): RawCodeCoverageData
    {
        return RawCodeCoverageData::fromLineCoverage(array_shift($this->stops) ?? []);
    }
}
