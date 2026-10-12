<?php

declare(strict_types=1);
use NightWorksIO\MutationGate\Cli\Flow\Since;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('reads nothing where no result needs what changed since its commit', function (): void {
    expect(new Since(Flows::adapters(Scratch::directory()), Flows::settings())->of()->warnings())->toHaveCount(0);
});
