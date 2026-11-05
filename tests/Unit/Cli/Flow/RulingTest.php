<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Flow\Adapters;
use NightWorksIO\MutationGate\Cli\Flow\Ruling;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Mutant\NamedMutators;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Mutator\Engine\Enabled;
use NightWorksIO\MutationGate\Mutator\MutatorSet;
use NightWorksIO\MutationGate\Tests\Fakes\StaticCheckerFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Mutators\PlusToMinus;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;

afterEach(function (): void {
    Scratch::sweep();
});

it('builds a group\'s runner, analyser and mutators from its settings, keeping every other port and narrowing', function (): void {
    $project = Flows::project();
    $narrowing = Narrowing::none()->toMutators(Mutators::named('Plus'));
    $adapters = Flows::adapters($project, [], $narrowing);
    $runner = ScriptedRunner::fixture()->named('ruled');
    $mutators = Enabled::of(MutatorSet::of(PlusToMinus::class));
    $built = [];
    $ruled = Ruling::by(static function (Settings $settings, Directory $at) use (&$built, $runner, $mutators): Adapters {
        $built[] = $at->root()->value();

        return Flows::adapters($at->root()->value(), [], $runner, $mutators, NamedMutators::of('Plus'), StaticCheckerFake::findingNothing());
    })->adapters($adapters, Flows::settings());

    expect($built)->toBe([$project])
        ->and($ruled instanceof Adapters ? [$ruled->runner, $ruled->narrowing, $ruled->proofs, $ruled->project] : $ruled)
        ->toBe([$runner, $narrowing, $adapters->proofs, $adapters->project])
        ->and($ruled instanceof Adapters ? [...$ruled->mutators] : $ruled)->toEqual([...$mutators])
        ->and($ruled instanceof Adapters ? $ruled->security : $ruled)->toEqual(NamedMutators::of('Plus'))
        ->and($ruled instanceof Adapters ? $ruled->checker : $ruled)->toBeInstanceOf(StaticCheckerFake::class);
});

it('says why a group\'s adapters cannot be built, and that none can without the wiring', function (): void {
    $adapters = Flows::adapters(Flows::project());
    $invalid = Invalid::because(Problem::at('paths[0].mutators.except', 'no such mutator'));

    expect(Ruling::by(static fn(): Invalid => $invalid)->adapters($adapters, Flows::settings()))->toBe($invalid)
        ->and(new Ruling()->adapters($adapters, Flows::settings()))
        ->toEqual(CannotJudge::because('The run was composed without the wiring that builds a runner for the units paths entries match.'));
});
