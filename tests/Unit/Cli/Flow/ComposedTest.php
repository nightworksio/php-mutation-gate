<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Cli\Flow\DecidingConfig;
use NightWorksIO\MutationGate\Cli\Flow\Setup;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Mutant\NamedMutators;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Briefing;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\SuiteName;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Support\FlowCommands;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;
use NightWorksIO\MutationGate\Tests\Support\StoppedClock;
use Symfony\Component\Console\Input\ArrayInput;

afterEach(function (): void {
    Scratch::sweep();
});

/** What a flow runs with in a project whose config holds these settings besides the fake runner. */
function composedWith(string $config): Composed
{
    $composition = FlowCommands::composition(
        FlowCommands::project($config),
        ScriptedRunner::fixture(),
        new ProofStoreFake(),
        Flows::ci(),
    );
    $composed = $composition->compose(new ArrayInput([]));

    return $composed instanceof Composed ? $composed : throw new RuntimeException('The flow did not compose.');
}

it('runs with a budget of its own, and every other setting and adapter as it was', function (): void {
    $before = composedWith('"budget": "1h", "shards": {"max": 3}');

    $after = $before->budgeted(Seconds::minutes(5));

    expect($after instanceof Composed ? $after->settings->triage()->budget() : $after)->toEqual(Seconds::minutes(5))
        ->and($after instanceof Composed ? $after->settings->shards()->max() : $after)->toBe(3)
        ->and($after instanceof Composed ? $after->settings->canonical() : $after)->toBe($before->settings->canonical())
        ->and($after instanceof Composed ? [$after->adapters, $after->setup, $after->reporting] : $after)
        ->toBe([$before->adapters, $before->setup, $before->reporting])
        ->and($before->settings->triage()->budget())->toEqual(Seconds::minutes(60));
});

it('is invalid where the settings no longer hold on the day its clock reads', function (): void {
    $before = composedWith('"ignores": {"maxDays": 30, "entries": [{"mutant": "3f9a1c2b7d04", "reason": "R", "expires": "2026-10-30"}]}');
    $earlier = new Composed(
        $before->settings,
        $before->adapters,
        new Setup($before->setup->configFile, $before->setup->gate, $before->setup->installed, new StoppedClock('2026-09-01T12:00:00+00:00'), $before->setup->memory, DecidingConfig::unread()),
        $before->reporting,
    );

    expect($earlier->budgeted(Seconds::minutes(5)))->toBeInstanceOf(Invalid::class);
});

/** What a flow runs with, whose mutants these mutators make as security mutants. */
function composedSecuring(string ...$security): Composed
{
    $before = composedWith('');

    return new Composed(
        $before->settings,
        Flows::adapters(Flows::project(), [], NamedMutators::of(...$security)),
        $before->setup,
        $before->reporting,
    );
}

it('narrows its runs to the security mutators, and its plans say so', function (): void {
    $before = composedSecuring('security/HashEqualsToTrue', 'default/UnwrapHtmlspecialchars');
    $after = $before->securityOnly();

    expect($after instanceof Composed ? $after->adapters->narrowing->mutators() : $after)
        ->toEqual(Mutators::named('security/HashEqualsToTrue', 'default/UnwrapHtmlspecialchars'))
        ->and($after instanceof Composed ? $after->adapters->isSecurityOnly() : $after)->toBeTrue()
        ->and($after instanceof Composed ? $after->adapters->briefing(Briefing::standard()) : $after)
        ->toEqual(Briefing::standard()->securityOnly())
        ->and($before->adapters->isSecurityOnly())->toBeFalse()
        ->and($before->adapters->narrowing->mutators())->toEqual(Mutators::all())
        ->and($before->adapters->briefing(Briefing::standard()))->toEqual(Briefing::standard())
        ->and($after instanceof Composed ? [$after->settings, $after->setup, $after->reporting] : $after)
        ->toBe([$before->settings, $before->setup, $before->reporting]);
});

it('cannot narrow its runs to the security mutators where the config turns none on', function (): void {
    expect(composedSecuring()->securityOnly())->toEqual(CannotJudge::because(<<<'SAID'
        --security makes mutants with the security-tagged mutators, and the config turns none on.
        Turn on the security set in mutators.sets, or a preset that offers it.
        SAID));
});

it('follows a plan made with --security, and runs every mutator for any other', function (): void {
    $composed = composedSecuring('security/HashEqualsToTrue');
    $secured = $composed->following(Planned::oneShard()->briefed(Briefing::standard()->securityOnly()));

    expect($secured instanceof Composed ? $secured->adapters->narrowing->mutators() : $secured)
        ->toEqual(Mutators::named('security/HashEqualsToTrue'))
        ->and($composed->following(Planned::oneShard()))->toBe($composed)
        ->and(composedSecuring()->following(Planned::oneShard()->briefed(Briefing::standard()->securityOnly())))
        ->toBeInstanceOf(CannotJudge::class);
});

/** What a flow runs with in a project whose PHPUnit config declares these suites. */
function composedInSuites(string $suites, string ...$security): Composed
{
    $before = composedWith('');
    $project = Flows::project();
    Scratch::write($project, 'phpunit.xml', sprintf('<?xml version="1.0"?><phpunit><testsuites>%s</testsuites></phpunit>', $suites));

    return new Composed(
        $before->settings,
        Flows::adapters($project, [], NamedMutators::of(...$security)),
        $before->setup,
        $before->reporting,
    );
}

it('narrows its runs to one declared suite\'s tests, the coverage run\'s among them, and its plans say so', function (): void {
    $before = composedInSuites('<testsuite name="unit"><directory>tests/Unit</directory></testsuite><testsuite name="feature"/>');
    $after = $before->inSuite(SuiteName::of('unit'));
    $run = CoverageRun::of(WholeSuite::tests(), Path::of('cov'));

    expect($after instanceof Composed ? $after->adapters->narrowing->suite() : $after)->toEqual(SuiteName::of('unit'))
        ->and($after instanceof Composed ? $after->adapters->isSuiteOnly() : $after)->toBeTrue()
        ->and($after instanceof Composed ? $after->adapters->isSecurityOnly() : $after)->toBeFalse()
        ->and($after instanceof Composed ? $after->adapters->briefing(Briefing::standard()) : $after)
        ->toEqual(Briefing::standard()->inSuite(SuiteName::of('unit')))
        ->and($after instanceof Composed ? $after->adapters->covering($run) : $after)
        ->toEqual($run->withholding($before->adapters->withheld)->inSuite(SuiteName::of('unit')))
        ->and($before->adapters->isSuiteOnly())->toBeFalse()
        ->and($before->adapters->covering($run))->toEqual($run->withholding($before->adapters->withheld))
        ->and($before->adapters->covering($run)->withheld())->not->toEqual(Withheld::standard())
        ->and($before->adapters->covering($run)->suite())->toEqual(NotGiven::value())
        ->and($after instanceof Composed ? [$after->settings, $after->setup, $after->reporting] : $after)
        ->toBe([$before->settings, $before->setup, $before->reporting]);
});

it('narrows to the security mutators and one suite at once, and its plans say both', function (): void {
    $secured = composedInSuites('<testsuite name="unit"/>', 'security/HashEqualsToTrue')->securityOnly();
    $both = $secured instanceof Composed ? $secured->inSuite(SuiteName::of('unit')) : $secured;

    expect($both instanceof Composed ? $both->adapters->briefing(Briefing::standard()) : $both)
        ->toEqual(Briefing::standard()->securityOnly()->inSuite(SuiteName::of('unit')))
        ->and($both instanceof Composed ? $both->adapters->narrowing->mutators() : $both)
        ->toEqual(Mutators::named('security/HashEqualsToTrue'));
});

it('refuses a suite the PHPUnit config does not declare, naming those it does', function (): void {
    $hostile = "e2e\e[31m\nred";

    expect(composedInSuites('<testsuite name="unit"/><testsuite name="feature"/>')->inSuite(SuiteName::of('e2e')))
        ->toEqual(CannotJudge::because('--suite=e2e names no test suite. The PHPUnit config declares: unit, feature.'))
        ->and(composedInSuites('<testsuite name="unit&#10;&#x202E;tixe"/>')->inSuite(SuiteName::of($hostile)))
        ->toEqual(CannotJudge::because('--suite=e2e[31m red names no test suite. The PHPUnit config declares: unit tixe.'))
        ->and(composedInSuites('')->inSuite(SuiteName::of('unit')))
        ->toEqual(CannotJudge::because('--suite=unit names no test suite: the PHPUnit config declares none by name.'));
});

it('refuses any suite where the PHPUnit config cannot be read', function (): void {
    $before = composedWith('');
    $project = Flows::project();
    mkdir(sprintf('%s/phpunit.xml', $project));
    $unread = new Composed($before->settings, Flows::adapters($project), $before->setup, $before->reporting);

    expect($unread->inSuite(SuiteName::of('unit')))->toBeInstanceOf(CannotJudge::class);
});

it('follows a plan made with --suite, beside --security or alone', function (): void {
    $composed = composedInSuites('<testsuite name="unit"/>', 'security/HashEqualsToTrue');
    $suited = $composed->following(Planned::oneShard()->briefed(Briefing::standard()->inSuite(SuiteName::of('unit'))));
    $both = $composed->following(Planned::oneShard()->briefed(
        Briefing::standard()->securityOnly()->inSuite(SuiteName::of('unit')),
    ));

    expect($suited instanceof Composed ? $suited->adapters->narrowing->suite() : $suited)->toEqual(SuiteName::of('unit'))
        ->and($suited instanceof Composed ? $suited->adapters->isSecurityOnly() : $suited)->toBeFalse()
        ->and($both instanceof Composed ? $both->adapters->briefing(Briefing::standard()) : $both)
        ->toEqual(Briefing::standard()->securityOnly()->inSuite(SuiteName::of('unit')))
        ->and(composedInSuites('<testsuite name="unit"/>')->following(
            Planned::oneShard()->briefed(Briefing::standard()->securityOnly()->inSuite(SuiteName::of('unit'))),
        ))->toBeInstanceOf(CannotJudge::class)
        ->and($composed->following(Planned::oneShard()->briefed(Briefing::standard()->inSuite(SuiteName::of('e2e')))))
        ->toBeInstanceOf(CannotJudge::class);
});
