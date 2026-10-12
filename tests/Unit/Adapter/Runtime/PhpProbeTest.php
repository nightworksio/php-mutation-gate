<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Runtime\PhpProbe;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('gives a PHP thirty seconds to describe itself', function (): void {
    expect(PhpProbe::of('/usr/bin/php', ['A' => 'b']))->toEqual(new PhpProbe('/usr/bin/php', ['A' => 'b'], Seconds::of(30.0)));
});
