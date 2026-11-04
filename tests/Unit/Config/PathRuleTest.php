<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Config\Gate;
use NightWorksIO\MutationGate\Config\Mutators;
use NightWorksIO\MutationGate\Config\PathRule;
use NightWorksIO\MutationGate\Config\Runner;
use NightWorksIO\MutationGate\Config\StaticCheck;
use NightWorksIO\MutationGate\Config\Timeouts;
use NightWorksIO\MutationGate\Core\Runner\PathRuleMode;
use NightWorksIO\MutationGate\Tests\Support\Configs;

it('writes a paths entry for each glob, in order, and how a shard applies them', function (): void {
    $gate = Gate::configure()->runner(Runner::pest()->applyingPathRules(PathRuleMode::Partitioned))->with(
        PathRule::matching('src/Legacy/**', Gate::configure()->with(Timeouts::seconds(60), Mutators::except('default/Plus'))),
        PathRule::matching('src/Gen/**', Gate::configure()->with(StaticCheck::none())),
    );

    expect(Configs::written($gate))->toBe([
        'runner' => ['use' => 'pest', 'pathRules' => 'partitioned'],
        'paths' => [
            ['glob' => 'src/Legacy/**', 'timeouts' => ['seconds' => 60], 'mutators' => ['except' => ['default/Plus']]],
            ['glob' => 'src/Gen/**', 'staticCheck' => ['tool' => 'none']],
        ],
    ])->and(Configs::written(Gate::configure()->with(PathRule::applying(PathRuleMode::Partitioned))))
        ->toBe(['runner' => ['pathRules' => 'partitioned']]);
});
