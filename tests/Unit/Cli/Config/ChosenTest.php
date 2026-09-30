<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Config\Chosen;
use NightWorksIO\MutationGate\Cli\Registry\Lookup;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Extension\Configurable;
use NightWorksIO\MutationGate\Extension\Extension;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Extension\Options;
use NightWorksIO\MutationGate\Extension\Origin;
use NightWorksIO\MutationGate\Port\CiPlan;
use NightWorksIO\MutationGate\Port\ProofStore;
use NightWorksIO\MutationGate\Port\Reporter;
use NightWorksIO\MutationGate\Port\Runner;
use NightWorksIO\MutationGate\Port\TreeSource;
use NightWorksIO\MutationGate\Tests\Fakes\CiPlanFake;
use NightWorksIO\MutationGate\Tests\Fakes\ExtensionFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\RunnerFake;
use NightWorksIO\MutationGate\Tests\Fakes\TreeSourceFake;
use NightWorksIO\MutationGate\Tests\Support\ConfigurableReporter;
use NightWorksIO\MutationGate\Tests\Support\MisbuiltReporter;
use NightWorksIO\MutationGate\Tests\Support\NotAReporter;

/** A registry with one adapter of every kind a config chooses, each registered as "it". */
$registry = static fn(): Extensions => new Extensions(Origin::of('acme/gate'))
    ->withRunner(Name::of('it'), static fn(): Runner => RunnerFake::ofTheFixture())
    ->withTreeSource(Name::of('it'), static fn(): TreeSource => TreeSourceFake::ofTheFixture())
    ->withProofStore(Name::of('it'), static fn(): ProofStore => new ProofStoreFake())
    ->withCiPlan(
        Name::of('it'),
        static fn(): CiPlan => new CiPlanFake(ShardId::of(1), CannotTell::because('A fake run.')),
    )
    ->withRunner(
        Name::of('picky'),
        static fn(): Invalid => Invalid::because(Problem::at('workers', 'expected a number')),
    );

/** Nothing registered, so only a class can be chosen. */
$classes = static fn(): Chosen => new Chosen(new Extensions(Origin::of('acme/gate')));

it('builds the adapter an extension registered under the name a setting chooses', function () use ($registry): void {
    $chosen = new Chosen($registry());

    expect($chosen->runner(Choice::of('it', '{}')))->toEqual(RunnerFake::ofTheFixture())
        ->and($chosen->treeSource(Choice::of('it', '{}')))->toEqual(TreeSourceFake::ofTheFixture())
        ->and($chosen->proofStore(Choice::of('it', '{}')))->toBeInstanceOf(ProofStoreFake::class)
        ->and($chosen->ciPlan(Choice::of('it', '{}')))
        ->toEqual(new CiPlanFake(ShardId::of(1), CannotTell::because('A fake run.')));
});

it('cannot judge with a name nothing registered', function () use ($registry): void {
    expect(new Chosen($registry())->runner(Choice::of('pset', '{}')))
        ->toEqual(CannotJudge::because('No runner is registered as "pset".'));
});

it('puts the problems a registered adapter has with its options under with', function () use ($registry): void {
    expect(new Chosen($registry())->runner(Choice::of('picky', '{"workers": "4"}')))
        ->toEqual(Invalid::because(Problem::at('runner.with.workers', 'expected a number')));
});

it('builds a class a config names from its options', function () use ($classes): void {
    $reporter = $classes()->reporter(Choice::of(ConfigurableReporter::class, '{"channel": "#ci"}'), 0);

    expect($reporter instanceof ConfigurableReporter ? $reporter->channel : $reporter)->toBe('#ci');
});

it('puts the problems a class has with its options under with, as the gate\'s own', function () use ($classes): void {
    expect($classes()->reporter(Choice::of(ConfigurableReporter::class, '{}'), 2))->toEqual(Invalid::because(
        Problem::at('reports[2].with.channel', 'expected a channel name, got nothing'),
        Problem::at('reports[2].with', 'needs a channel'),
    ));
});

it('refuses a class that is not configurable, or adapts another port', function (string $class, string $port) use (
    $classes,
): void {
    $built = $port === Runner::class
        ? $classes()->runner(Choice::of($class, '{}'))
        : $classes()->reporter(Choice::of($class, '{}'), 0);

    expect($built)->toEqual(CannotJudge::because(sprintf(
        '%s is not a class that implements %s and %s, so a config cannot choose it.',
        $class,
        $port,
        Configurable::class,
    )));
})->with([
    'a class not there' => ['Acme\\Missing\\Reporter', Reporter::class],
    'a configurable class of another port' => [ConfigurableReporter::class, Runner::class],
    'a configurable class of no port' => [NotAReporter::class, Reporter::class],
    'a class that is not configurable' => [RunnerFake::class, Runner::class],
]);

it('refuses a class whose named constructor builds something else', function () use ($classes): void {
    expect($classes()->reporter(Choice::of(MisbuiltReporter::class, '{}'), 0))->toEqual(CannotJudge::because(sprintf(
        '%s::fromOptions() built something other than a %s.',
        MisbuiltReporter::class,
        Reporter::class,
    )));
});

it('loads the extensions a config names, as coming from the config file', function (): void {
    $registry = new Chosen(new Extensions(Origin::of('nightworksio/mutation-gate')))
        ->withExtensions([ExtensionFake::class], 'mutation-gate.json');

    expect($registry instanceof Extensions ? Lookup::in($registry)->runner(Name::of('fake'), Options::none()) : $registry)
        ->toEqual(RunnerFake::ofTheFixture());
});

it('refuses an extension a config names that is not one', function () use ($classes): void {
    expect($classes()->withExtensions([RunnerFake::class, ExtensionFake::class], 'mutation-gate.json'))
        ->toEqual(CannotJudge::because(sprintf(
            'mutation-gate.json names %s in extensions, and it is not a class that implements %s.',
            RunnerFake::class,
            Extension::class,
        )));
});

it('refuses an extension a config names that registers what another package does', function (): void {
    $discovered = new Extensions(Origin::of('acme/one'))
        ->withRunner(Name::of('fake'), static fn(): Runner => RunnerFake::ofTheFixture());

    expect(new Chosen($discovered)->withExtensions([ExtensionFake::class], 'mutation-gate.json'))
        ->toEqual(CannotJudge::because(
            'Two packages register a runner named "fake": acme/one and mutation-gate.json. '
            . 'Remove one of the packages, or run with --no-extensions.',
        ));
});

it('suggests the registered name a misspelt one most likely meant', function () use ($registry): void {
    expect(new Chosen($registry())->runner(Choice::of('pickey', '{}')))
        ->toEqual(CannotJudge::because('No runner is registered as "pickey". Did you mean "picky"?'));
});

it('builds a class in the global namespace a config names without a backslash', function () use ($classes): void {
    expect($classes()->runner(Choice::of('ArrayObject', '{}')))->toEqual(CannotJudge::because(sprintf(
        'ArrayObject is not a class that implements %s and %s, so a config cannot choose it.',
        Runner::class,
        Configurable::class,
    )));
});
