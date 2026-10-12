<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Adapter\Pest\Order\Seed;

/** The directories the tests of Pest's reordering run in. */
final readonly class Reorderings
{
    /** An order directory holding an order for the mutated copy at /tmp/mutations/abc. */
    public static function directory(): string
    {
        $order = Scratch::directory();
        Scratch::write(Seed::directoryOf($order, '/tmp/mutations/abc'), Seed::HISTORY, '{}');

        return $order;
    }
}
