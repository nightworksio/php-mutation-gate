<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Cli\Flow\Setup;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Mutant\NamedMutators;
use NightWorksIO\MutationGate\Core\Plan\Briefing;
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
        new Setup($before->setup->configFile, $before->setup->gate, $before->setup->installed, new StoppedClock('2026-09-01T12:00:00+00:00'), $before->setup->memory),
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

    expect($after instanceof Composed ? $after->adapters->narrowedTo : $after)
        ->toEqual(Mutators::named('security/HashEqualsToTrue', 'default/UnwrapHtmlspecialchars'))
        ->and($after instanceof Composed ? $after->adapters->isSecurityOnly() : $after)->toBeTrue()
        ->and($after instanceof Composed ? $after->adapters->briefing(Briefing::standard()) : $after)
        ->toEqual(Briefing::standard()->securityOnly())
        ->and($before->adapters->isSecurityOnly())->toBeFalse()
        ->and($before->adapters->narrowedTo)->toEqual(Mutators::all())
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

    expect($secured instanceof Composed ? $secured->adapters->narrowedTo : $secured)
        ->toEqual(Mutators::named('security/HashEqualsToTrue'))
        ->and($composed->following(Planned::oneShard()))->toBe($composed)
        ->and(composedSecuring()->following(Planned::oneShard()->briefed(Briefing::standard()->securityOnly())))
        ->toBeInstanceOf(CannotJudge::class);
});
