<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\CapIni;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;

it('writes the cap, and prints each error on the standard output where the runner reads only that', function (): void {
    $cap = MemoryCap::of(64, MemoryUnit::Megabytes);

    expect(CapIni::of($cap)->text())->toBe("memory_limit=64M\n")
        ->and(CapIni::of($cap)->showingErrors()->text())->toBe("memory_limit=64M\ndisplay_errors=stdout\n");
});
