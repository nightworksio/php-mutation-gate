<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Fakes;

use function array_shift;

use NightWorksIO\MutationGate\Adapter\Pest\Split\LineCollector;

/**
 * A line collector that answers each collect with the marks it was handed for
 * it, and keeps each call it took, so a test of a stream's recording leaves
 * the suite's own pcov alone.
 */
final class LineCollectorFake implements LineCollector
{
    /** @var list<string> each call it took, by name */
    private array $calls = [];

    /** @param list<array<string, array<int, int>>> $collects the marks each collect answers, by file */
    public function __construct(private array $collects = [])
    {
    }

    /** @return list<string> each call it took, by name */
    public function calls(): array
    {
        return $this->calls;
    }

    public function clear(): void
    {
        $this->calls[] = 'clear';
    }

    public function start(): void
    {
        $this->calls[] = 'start';
    }

    public function stop(): void
    {
        $this->calls[] = 'stop';
    }

    public function collect(): array
    {
        $this->calls[] = 'collect';

        return array_shift($this->collects) ?? [];
    }
}
