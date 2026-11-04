<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Config\Budget;
use NightWorksIO\MutationGate\Config\Context;
use NightWorksIO\MutationGate\Config\Gate;
use NightWorksIO\MutationGate\Config\Runner;
use NightWorksIO\MutationGate\Config\Shards;
use NightWorksIO\MutationGate\Tests\Support\Configs;

it('writes a block for each context, as the builder that writes the rest writes its settings', function (): void {
    $gate = Gate::configure()->runner(Runner::pest())->with(
        Context::local(Gate::configure()->with(Budget::of('2m'))),
        Context::ci(Gate::configure()->with(Shards::max(4))),
        Context::pullRequest(Gate::configure()->runner(Runner::infection())),
        Context::main(Gate::configure()->with(Budget::of('1h'))),
    );

    expect(Configs::written($gate))->toBe([
        'runner' => 'pest',
        'contexts' => [
            'local' => ['budget' => '2m'],
            'ci' => ['shards' => ['max' => 4]],
            'pr' => ['runner' => 'infection'],
            'main' => ['budget' => '1h'],
        ],
    ]);
});
