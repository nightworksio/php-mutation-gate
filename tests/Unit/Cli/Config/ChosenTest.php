<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Config\Chosen;
use NightWorksIO\MutationGate\Cli\Registry\Lookup;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\CiMarker;
use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\Ci;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Extension\Configurable;
use NightWorksIO\MutationGate\Extension\Extension;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Port\CiPlan;
use NightWorksIO\MutationGate\Port\ProofStore;
use NightWorksIO\MutationGate\Port\Reporter;
use NightWorksIO\MutationGate\Port\Runner;
use NightWorksIO\MutationGate\Port\StaticChecker;
use NightWorksIO\MutationGate\Port\TreeSource;
use NightWorksIO\MutationGate\Tests\Fakes\CiPlanFake;
use NightWorksIO\MutationGate\Tests\Fakes\ExtensionFake;
use NightWorksIO\MutationGate\Tests\Fakes\ExtensionThatCannotStart;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\ReporterFake;
use NightWorksIO\MutationGate\Tests\Fakes\RunnerFake;
use NightWorksIO\MutationGate\Tests\Fakes\StaticCheckerFake;
use NightWorksIO\MutationGate\Tests\Fakes\TreeSourceFake;
use NightWorksIO\MutationGate\Tests\Support\Configs;
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
        static fn(): CiPlan => new CiPlanFake(CannotTell::because('A fake run.')),
        CiPlanFake::withheld(),
        CiPlanFake::marker(),
    )
    ->withStaticChecker(Name::of('it'), static fn(): StaticChecker => StaticCheckerFake::findingNothing())
    ->withRunner(
        Name::of('picky'),
        static fn(): Invalid => Invalid::because(Problem::at('workers', 'expected a number')),
    )
    ->withReporter(Name::of('it'), static fn(): Reporter => new ReporterFake())
    ->withReporter(
        Name::of('picky'),
        static fn(): Invalid => Invalid::because(Problem::at('colors', 'expected a map of colours')),
    );

/** Nothing registered, so only a class can be chosen. */
$classes = static fn(): Chosen => new Chosen(new Extensions(Origin::of('acme/gate')));

it('builds the adapter an extension registered under the name a setting chooses', function () use ($registry): void {
    $chosen = new Chosen($registry());

    expect($chosen->runner(Choice::of('it', Configs::options('{}'))))->toEqual(RunnerFake::ofTheFixture())
        ->and($chosen->treeSource(Choice::of('it', Configs::options('{}'))))->toEqual(TreeSourceFake::ofTheFixture())
        ->and($chosen->proofStore(Choice::of('it', Configs::options('{}'))))->toBeInstanceOf(ProofStoreFake::class)
        ->and($chosen->ciPlan(Choice::of('it', Configs::options('{}'))))
        ->toEqual(new CiPlanFake(CannotTell::because('A fake run.')))
        ->and($chosen->staticChecker(Choice::of('it', Configs::options('{}'))))
        ->toEqual(StaticCheckerFake::findingNothing());
});

it('names the static analyser nothing registered by its setting', function () use ($registry): void {
    expect(new Chosen($registry())->staticChecker(Choice::of('phpstna', Configs::options('{}'))))
        ->toEqual(CannotJudge::because('No static checker is registered as "phpstna".'));
});

it('cannot judge with a name nothing registered', function () use ($registry): void {
    expect(new Chosen($registry())->runner(Choice::of('pset', Configs::options('{}'))))
        ->toEqual(CannotJudge::because('No runner is registered as "pset".'));
});

it('puts the problems a registered adapter has with its options under with', function () use ($registry): void {
    expect(new Chosen($registry())->runner(Choice::of('picky', Configs::options('{"workers": "4"}'))))
        ->toEqual(Invalid::because(Problem::at('runner.with.workers', 'expected a number')));
});

it('builds a reporter the run chooses itself, putting its problems under what chose it', function () use (
    $registry,
): void {
    $chosen = new Chosen($registry());
    $none = Options::none();

    expect($chosen->reporterChosenBy('badge', Choice::of('it', $none)))->toBeInstanceOf(ReporterFake::class)
        ->and($chosen->reporterChosenBy('badge', Choice::of('picky', $none)))
        ->toEqual(Invalid::because(Problem::at('badge.with.colors', 'expected a map of colours')))
        ->and($chosen->reporterChosenBy('GITHUB_ACTIONS', Choice::of('github', $none)))
        ->toEqual(CannotJudge::because('No reporter is registered as "github".'));
});

it('builds a class a config names from its options', function () use ($classes): void {
    $reporter = $classes()->reporter(Choice::of(ConfigurableReporter::class, Configs::options('{"channel": "#ci"}')), 0);

    expect($reporter instanceof ConfigurableReporter ? $reporter->channel : $reporter)->toBe('#ci');
});

it('puts the problems a class has with its options under with, as the gate\'s own', function () use ($classes): void {
    expect($classes()->reporter(Choice::of(ConfigurableReporter::class, Configs::options('{}')), 2))->toEqual(Invalid::because(
        Problem::at('reports[2].with.channel', 'expected a channel name, got nothing'),
        Problem::at('reports[2].with', 'needs a channel'),
    ));
});

it('puts the problem the options find with a class\'s option under with', function () use ($classes): void {
    expect($classes()->reporter(Choice::of(ConfigurableReporter::class, Configs::options('{"channel": 7}')), 1))
        ->toEqual(Invalid::because(Problem::at('reports[1].with.channel', 'expected text, got 7')));
});

it('refuses a class that is not configurable, or adapts another port', function (string $class, string $port) use (
    $classes,
): void {
    $built = $port === Runner::class
        ? $classes()->runner(Choice::of($class, Configs::options('{}')))
        : $classes()->reporter(Choice::of($class, Configs::options('{}')), 0);

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
    expect($classes()->reporter(Choice::of(MisbuiltReporter::class, Configs::options('{}')), 0))->toEqual(CannotJudge::because(sprintf(
        '%s::fromOptions() built something other than a %s.',
        MisbuiltReporter::class,
        Reporter::class,
    )));
});

it('loads the extensions a config names, as coming from the config file', function (): void {
    $registry = new Chosen(new Extensions(Origin::of('nightworksio/mutation-gate')))
        ->withExtensions([ExtensionFake::class]);

    expect($registry instanceof Extensions ? Lookup::in($registry)->runner(Name::of('fake'), Options::none()) : $registry)
        ->toEqual(RunnerFake::ofTheFixture());
});

it('refuses an extension a config names that fails as it starts', function () use ($classes): void {
    expect($classes()->withExtensions([ExtensionThatCannotStart::class, ExtensionFake::class]))
        ->toEqual(CannotJudge::because(sprintf(
            'the config file names %s in extensions, and it failed as it started: %s',
            ExtensionThatCannotStart::class,
            'the settings file of this extension is missing',
        )));
});

it('refuses an extension a config names that is not one', function () use ($classes): void {
    expect($classes()->withExtensions([RunnerFake::class, ExtensionFake::class]))
        ->toEqual(CannotJudge::because(sprintf(
            'the config file names %s in extensions, and it is not a class that implements %s.',
            RunnerFake::class,
            Extension::class,
        )));
});

it('refuses an extension a config names that registers what another package does', function (): void {
    $discovered = new Extensions(Origin::of('acme/one'))
        ->withRunner(Name::of('fake'), static fn(): Runner => RunnerFake::ofTheFixture());

    expect(new Chosen($discovered)->withExtensions([ExtensionFake::class]))
        ->toEqual(CannotJudge::because(
            'Two packages register a runner named "fake": acme/one and the config file. '
            . 'Remove one of the packages, or run with --no-extensions.',
        ));
});

it('suggests the registered name a misspelt one most likely meant', function () use ($registry): void {
    expect(new Chosen($registry())->runner(Choice::of('pickey', Configs::options('{}'))))
        ->toEqual(CannotJudge::because('No runner is registered as "pickey". Did you mean "picky"?'));
});

it('takes a class in the global namespace by its leading backslash, and a word without one as a name', function () use (
    $classes,
): void {
    expect($classes()->runner(Choice::of('\\ArrayObject', Configs::options('{}'))))->toEqual(CannotJudge::because(sprintf(
        '\\ArrayObject is not a class that implements %s and %s, so a config cannot choose it.',
        Runner::class,
        Configurable::class,
    )))->and($classes()->runner(Choice::of('ArrayObject', Configs::options('{}'))))
        ->toEqual(CannotJudge::because(
            'No runner is registered as "ArrayObject". A class is written with its namespace, so the class ArrayObject '
            . 'is \\ArrayObject.',
        ))
        ->and($classes()->runner(Choice::of('arrayobjects', Configs::options('{}'))))
        ->toEqual(CannotJudge::because('No runner is registered as "arrayobjects".'));
});

it('withholds what every registered CI plan declares, whether or not a config lets it build', function () use (
    $registry,
): void {
    $chosen = new Chosen($registry()->withCiPlan(
        Name::of('broken'),
        static fn(): Invalid => Invalid::because(Problem::at('template', 'expected a path')),
        Withheld::of('BROKEN_CI_TOKEN'),
        CiMarker::none(),
    ));
    $unbuilt = Configs::settings(['runner' => 'pest', 'ci' => ['plan' => '\Acme\NoPlan']])->ci();

    expect([...$chosen->withheld(Ci::none(), Withheld::of('DEPLOY_*'), Listed::of())])
        ->toBe([...Withheld::standard(), 'FAKE_CI_TOKEN', 'BROKEN_CI_TOKEN', 'DEPLOY_*'])
        ->and([...$chosen->withheld($unbuilt, Withheld::nothing(), Listed::of())])
        ->toBe([...Withheld::standard(), 'FAKE_CI_TOKEN', 'BROKEN_CI_TOKEN']);
});

it('withholds what a CI plan class the config names declares, though no registry holds it', function () use (
    $classes,
): void {
    $named = Configs::settings(['runner' => 'pest', 'ci' => ['plan' => sprintf('\\%s', CiPlanFake::class)]])->ci();

    expect([...$classes()->withheld($named, Withheld::nothing(), Listed::of())])->toBe([...Withheld::standard(), 'FAKE_CI_TOKEN']);
});

it('withholds the variables a reports entry\'s alert channel reads, where its options name others', function () use (
    $registry,
): void {
    $reports = Configs::settings(['runner' => 'pest', 'reports' => [
        ['use' => 'slack', 'with' => ['urlEnv' => 'TEAM_SLACK_HOOK']],
        ['use' => 'webhook', 'with' => ['urlEnv' => 'HOOK_URL', 'secretEnv' => 'HOOK_KEY']],
        ['use' => 'discord'],
    ]])->reports();
    $withheld = [...new Chosen($registry())->withheld(Ci::none(), Withheld::nothing(), $reports)];

    expect($withheld)->toBe([
        ...Withheld::standard(),
        'FAKE_CI_TOKEN',
        'TEAM_SLACK_HOOK',
        'HOOK_URL',
        'HOOK_KEY',
    ]);
});
