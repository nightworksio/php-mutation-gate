<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Warm\Pool;
use NightWorksIO\MutationGate\Tests\Support\GateEnvironment;

beforeEach(function (): void {
    GateEnvironment::clear();
});

afterEach(function (): void {
    GateEnvironment::restore();
});

it('puts nothing before a mutant\'s run where the gate names no directory for warm workers', function (): void {
    expect(Pool::prefix(1))->toBe([]);
});
