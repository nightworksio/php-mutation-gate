<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Flow\Adapters;
use NightWorksIO\MutationGate\Cli\Flow\Wiring;
use NightWorksIO\MutationGate\Config\Mutators;
use NightWorksIO\MutationGate\Config\Runner;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Mutator\Engine\Engine;
use NightWorksIO\MutationGate\Mutator\MutatorSet;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Mutators\PlusToMinus;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Wirings;

afterEach(function (): void {
    Scratch::sweep();
});

it('cannot wire a mutator set nobody registered, naming the one most likely meant', function (): void {
    $registry = Wirings::registry()->withMutators(Name::of('acme'), MutatorSet::of(PlusToMinus::class));

    expect(new Wiring($registry, Variables::of([]), Wirings::detected())->adapters(
        Flows::settings(Runner::pest(), Mutators::sets('acmee')),
        Directory::at(Flows::project()),
    ))->toEqual(CannotJudge::because('No mutator set is registered as "acmee". Did you mean "acme"?'));
});

it('counts a plan\'s mutants with an engine of even one mutator, and with none of no mutator', function (bool $acme): void {
    $registry = Wirings::registry()->withMutators(Name::of('acme'), MutatorSet::of(PlusToMinus::class));
    $settings = $acme ? Flows::settings(Mutators::sets('acme')) : Flows::settings();
    $adapters = new Wiring($registry, Variables::of([]), Wirings::detected())->adapters($settings, Directory::at(Flows::project()));

    expect($adapters instanceof Adapters ? $adapters->engine : $adapters)->toEqual($acme ? Engine::with(new PlusToMinus()) : NotGiven::value());
})->with(['one mutator' => [true], 'none' => [false]]);
