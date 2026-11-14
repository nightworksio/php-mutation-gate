<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function pcov\clear;
use function pcov\collect;
use function pcov\start;
use function pcov\stop;

/**
 * pcov, collecting the lines a stream's parent executes in one test.
 *
 * @internal
 */
final readonly class PcovLines implements LineCollector
{
    public function clear(): void
    {
        clear();
    }

    public function start(): void
    {
        start();
    }

    public function stop(): void
    {
        stop();
    }

    public function collect(): array
    {
        return collect();
    }
}
