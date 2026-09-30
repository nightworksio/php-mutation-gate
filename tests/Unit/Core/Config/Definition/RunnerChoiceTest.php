<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Definition\Adapter;
use NightWorksIO\MutationGate\Core\Config\Definition\Builtins;
use NightWorksIO\MutationGate\Core\Config\Definition\RunnerChoice;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;

it('expects what any adapter expects', function (): void {
    expect(RunnerChoice::choosing(Builtins::runners(ProjectRoot::origin()))->expected())->toBe(Adapter::EXPECTED);
});
