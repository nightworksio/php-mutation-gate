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
        Context::gitHub(Gate::configure()->with(Budget::of('3m'))),
        Context::gitLab(Gate::configure()->with(Budget::of('4m'))),
        Context::bitbucket(Gate::configure()->with(Budget::of('5m'))),
        Context::azure(Gate::configure()->with(Budget::of('6m'))),
        Context::buildkite(Gate::configure()->with(Budget::of('7m'))),
        Context::jenkins(Gate::configure()->with(Budget::of('8m'))),
        Context::circleCi(Gate::configure()->with(Budget::of('9m'))),
    );

    expect(Configs::written($gate))->toBe([
        'runner' => 'pest',
        'contexts' => [
            'local' => ['budget' => '2m'],
            'ci' => ['shards' => ['max' => 4]],
            'pr' => ['runner' => 'infection'],
            'main' => ['budget' => '1h'],
            'github' => ['budget' => '3m'],
            'gitlab' => ['budget' => '4m'],
            'bitbucket' => ['budget' => '5m'],
            'azure' => ['budget' => '6m'],
            'buildkite' => ['budget' => '7m'],
            'jenkins' => ['budget' => '8m'],
            'circleci' => ['budget' => '9m'],
        ],
    ]);
});
