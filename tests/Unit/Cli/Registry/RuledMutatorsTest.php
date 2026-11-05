<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Registry\Lookup;
use NightWorksIO\MutationGate\Cli\Registry\RuledMutators;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Mutator\MutatorSet;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Mutators\DecrementToIncrement;
use NightWorksIO\MutationGate\Tests\Support\Mutators\PlusToMinus;
use NightWorksIO\MutationGate\Tests\Support\Mutators\RemoveEcho;

/** A registry with a `default` set and one other. */
$lookup = static fn(): Lookup => Lookup::in(Extensions::of(Origin::of('acme/gate'))
    ->withMutators(MutatorSet::defaultName(), MutatorSet::of(DecrementToIncrement::class))
    ->withMutators(Name::of('acme'), MutatorSet::of(PlusToMinus::class, RemoveEcho::class)));

/** What a config's `paths` entries turn on and off comes to, under this runner. */
$checked = static fn(string $runner, array $paths): NotGiven|Invalid|CannotJudge => RuledMutators::checked(
    $lookup(),
    Configs::settings(['runner' => $runner, 'mutators' => ['sets' => ['acme']], 'paths' => $paths]),
);

it('takes entries whose mutators a set the config or the entry turns on holds', function () use ($checked): void {
    expect($checked('pest', [['glob' => 'src/**', 'mutators' => ['except' => ['acme/RemoveEcho']]]]))->toEqual(NotGiven::value())
        ->and($checked('phpunit', [['glob' => 'src/**', 'mutators' => ['except' => ['acme/DecrementToIncrement']]]]))->toEqual(NotGiven::value())
        ->and($checked('pest', []))->toEqual(NotGiven::value());
});

it('refuses, at the entry\'s path, a mutator only the runner\'s own mutants stand in for', function () use ($checked): void {
    expect($checked('pest', [
        ['glob' => 'src/A/**'],
        ['glob' => 'src/B/**', 'mutators' => ['except' => ['acme/DecrementToIncrement']]],
    ]))->toEqual(Invalid::because(Problem::at(
        'paths[1].mutators.except',
        "expected a mutator of a set in mutators.sets, got \"acme/DecrementToIncrement\": the pest runner runs its own mutators in place of the default set's",
    )));
});

it('cannot judge an entry naming a set nothing registers, or a mutator no set holds, saying which entry', function () use ($checked): void {
    expect($checked('pest', [['glob' => 'src/**', 'mutators' => ['except' => ['acme/RemoveEco']]]]))
        ->toEqual(CannotJudge::because('paths[0]: mutators.except names "acme/RemoveEco", which neither a mutator set in mutators.sets nor the default set holds. Did you mean "acme/RemoveEcho"?'))
        ->and($checked('pest', [['glob' => 'src/**', 'mutators' => ['sets' => ['nowhere']]]]))
        ->toBeInstanceOf(CannotJudge::class);
});
