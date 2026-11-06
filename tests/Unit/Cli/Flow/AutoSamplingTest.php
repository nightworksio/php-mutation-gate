<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Mode;
use NightWorksIO\MutationGate\Cli\Flow\Planning;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Config\Sampling;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Plan\Cut;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Sample\Sample;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Mutator\Engine\Engine;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Fakes\CiPlanFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Mutators\PlusToMinus;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

$sum = "<?php\n\nfunction sum(int \$a, int \$b): int\n{\n    return \$a + \$b + 1;\n}\n";

/** A plan over a project that adds a sum to the flows' own, in this mode and these settings, with these ports in place of the fakes. */
$plan = static function (Mode $mode, Settings $settings, object ...$ports) use ($sum): Plan|CannotJudge {
    $project = Flows::project();
    Scratch::write($project, 'src/Sum.php', $sum);

    return Planned::from(new Planning(
        Flows::adapters($project, [], Engine::with(new PlusToMinus()), ...$ports),
        $settings,
        Flows::setup(),
    )->plan($mode, CoverageRun::of(WholeSuite::tests(), Workspace::coverage()), Cut::exactly(1), MatrixKind::FirstKiller));
};

/** The change source of a change to these paths, each at its third line. */
$changing = static function (string ...$paths) use ($sum): ChangeSourceFake {
    $files = [...Flows::FILES, 'src/Sum.php' => $sum];
    $changes = Changes::none();

    foreach ($paths as $path) {
        $changes = $changes->with(Change::modified(Path::of($path), Lines::of(Line::of(3))));
    }

    return new ChangeSourceFake(
        Revision::ref(Flows::MAIN),
        $changes,
        [Revision::workingTree()->name() => $files, 'base' => $files, Flows::MAIN => $files],
    );
};

$sampledOf = static fn(Plan|CannotJudge $plan): array => $plan instanceof Plan
    ? array_map(static fn(Unit $unit): string => $unit->path()->value(), [...$plan->considered()->sample()->sampled()])
    : [$plan->why()];
$reasonsOf = static fn(Plan|CannotJudge $plan): array => $plan instanceof Plan
    ? array_map(static fn(Reason $reason): string => $reason->text(), [...$plan->considered()->reach()])
    : [$plan->why()];
$pullRequest = static fn(): CiPlanFake => new CiPlanFake(RunOn::at(Scope::pullRequest(7), Scope::branch('main')));

it('samples, on a pull request reaching more beyond its new code than sampling.auto allows, every unit but the new code\'s, and says why', function () use ($plan, $changing, $sampledOf, $reasonsOf, $pullRequest): void {
    $planned = $plan(Mode::full(), Flows::settings(Sampling::auto(1)), $changing('src/Money.php'), $pullRequest());

    expect($sampledOf($planned))->toBe(['src/Sum.php'])
        ->and($reasonsOf($planned))->toBe([
            'A full run considers every unit.',
            'Beyond its new code the change reaches about 2 mutants, taking about 2s of runner time, over sampling.auto\'s 1 mutant, so the plan samples it (ADR-0086).',
        ]);
});

it('samples by itself nowhere at or under sampling.auto, by runner time or mutants, and nowhere by default', function (Settings $settings) use ($plan, $changing, $reasonsOf, $pullRequest): void {
    $planned = $plan(Mode::full(), $settings, $changing('src/Money.php'), $pullRequest());

    expect($planned instanceof Plan ? $planned->considered()->sample() : $planned)->toEqual(Sample::none())
        ->and($reasonsOf($planned))->toBe(['A full run considers every unit.']);
})->with([
    'at its mutants' => fn(): Settings => Flows::settings(Sampling::auto(2)),
    'at its runner time' => fn(): Settings => Flows::settings(Sampling::auto('2s')),
    'off' => fn(): Settings => Flows::settings(Sampling::autoOff()),
    'by default' => fn(): Settings => Flows::settings(),
]);

it('samples by itself above a runner time', function () use ($plan, $changing, $sampledOf, $pullRequest): void {
    expect($sampledOf($plan(Mode::full(), Flows::settings(Sampling::auto('1s')), $changing('src/Money.php'), $pullRequest())))
        ->toBe(['src/Sum.php']);
});

it('samples by itself only the kinds of run sampling.autoRuns lists, and never the default branch\'s or a tag\'s', function (RunOn $run, Settings $settings, array $sampled) use ($plan, $changing, $sampledOf): void {
    expect($sampledOf($plan(Mode::full(), $settings, $changing('src/Money.php'), new CiPlanFake($run))))->toBe($sampled);
})->with([
    'a merge group, by default' => fn(): array => [
        RunOn::mergeGroup(Revision::ref(Flows::MAIN), Scope::branch('main')),
        Flows::settings(Sampling::auto(1)),
        [],
    ],
    'a merge group, listed' => fn(): array => [
        RunOn::mergeGroup(Revision::ref(Flows::MAIN), Scope::branch('main')),
        Flows::settings(Sampling::auto(1), Sampling::autoRuns('mergeGroups')),
        ['src/Sum.php'],
    ],
    'a pull request, not listed' => fn(): array => [
        RunOn::at(Scope::pullRequest(7), Scope::branch('main')),
        Flows::settings(Sampling::auto(1), Sampling::autoRuns('branches')),
        [],
    ],
    'another branch, listed, which holds no new code' => fn(): array => [
        RunOn::at(Scope::branch('feat/x'), Scope::branch('main')),
        Flows::settings(Sampling::auto(1), Sampling::autoRuns('branches')),
        ['src/Sum.php', 'src/Money.php'],
    ],
    'the default branch, every kind listed' => fn(): array => [
        RunOn::at(Scope::branch('main'), Scope::branch('main')),
        Flows::settings(Sampling::auto(1), Sampling::autoRuns('pullRequests', 'mergeGroups', 'branches')),
        [],
    ],
    'a tag, every kind listed' => fn(): array => [
        RunOn::at(Scope::of('refs/tags/v1.0.0'), Scope::branch('main')),
        Flows::settings(Sampling::auto(1), Sampling::autoRuns('pullRequests', 'mergeGroups', 'branches')),
        [],
    ],
]);

it('leaves an unproven run and one asked to sample as they are', function () use ($plan, $changing, $reasonsOf, $pullRequest): void {
    $unproven = $plan(Mode::unproven(), Flows::settings(Sampling::auto(1)), $changing('src/Money.php'), $pullRequest());
    $asked = $plan(Mode::full()->sampled(), Flows::settings(Sampling::auto(1)), $changing('src/Money.php'), $pullRequest());

    expect($unproven instanceof Plan ? $unproven->considered()->sample() : $unproven)->toEqual(Sample::none())
        ->and($reasonsOf($unproven))->not->toContain('Beyond')
        ->and($reasonsOf($asked))->toBe(['A full run considers every unit.']);
});

it('samples the units a change-scoped run reaches beyond its new code, which a run asked to sample knows exactly', function () use ($plan, $changing, $sampledOf, $pullRequest): void {
    $changed = $changing('src/Money.php', 'tests/Pest.php');
    $itself = $plan(Mode::since(Flows::MAIN), Flows::settings(Sampling::auto(1)), $changed, $pullRequest());
    $asked = $plan(Mode::since(Flows::MAIN)->sampled(), Flows::settings(), $changed, $pullRequest());

    expect($sampledOf($itself))->toBe(['src/Sum.php'])
        ->and($sampledOf($asked))->toBe([]);
});

it('counts no unit of a tree held to 100, which runs whole, so samples nothing by itself there', function () use ($sum, $changing, $reasonsOf, $pullRequest): void {
    $project = Flows::project();
    Scratch::write($project, 'src/Sum.php', $sum);
    Scratch::write($project, 'mutation-gate.baseline.json', '{"format": 1, "trees": {"src": {"floor": 100}}}');
    $planned = Planned::from(new Planning(
        Flows::adapters($project, [], Engine::with(new PlusToMinus()), $changing('src/Money.php'), $pullRequest()),
        Flows::settings(Sampling::auto(1)),
        Flows::setup(),
    )->plan(Mode::full(), CoverageRun::of(WholeSuite::tests(), Workspace::coverage()), Cut::exactly(1), MatrixKind::FirstKiller));

    expect($planned instanceof Plan ? $planned->considered()->sample() : $planned)->toEqual(Sample::none())
        ->and($reasonsOf($planned))->toBe(['A full run considers every unit.']);
});

it('samples nothing by itself on a branch whose committed baseline it cannot read', function () use ($sum, $changing, $reasonsOf): void {
    $project = Flows::project();
    Scratch::write($project, 'src/Sum.php', $sum);
    Scratch::write($project, 'mutation-gate.baseline.json', 'not json');
    $planned = Planned::from(new Planning(
        Flows::adapters(
            $project,
            [],
            Engine::with(new PlusToMinus()),
            $changing('src/Money.php'),
            new CiPlanFake(RunOn::at(Scope::branch('feat/x'), Scope::branch('main'))),
        ),
        Flows::settings(Sampling::auto(1), Sampling::autoRuns('branches')),
        Flows::setup(),
    )->plan(Mode::full(), CoverageRun::of(WholeSuite::tests(), Workspace::coverage()), Cut::exactly(1), MatrixKind::FirstKiller));

    expect($reasonsOf($planned))->toBe(['A full run considers every unit.']);
});

it('refuses, sampling nothing, a pull request whose new code it cannot tell', function () use ($pullRequest): void {
    $project = Flows::project();
    Scratch::write($project, 'mutation-gate.baseline.json', 'not json');
    $planned = Planned::from(new Planning(
        Flows::adapters($project, [], $pullRequest()),
        Flows::settings(Sampling::auto(1)),
        Flows::setup(),
    )->plan(Mode::full(), CoverageRun::of(WholeSuite::tests(), Workspace::coverage()), Cut::exactly(1), MatrixKind::FirstKiller));

    expect($planned instanceof CannotJudge ? $planned->why() : '')->toStartWith('A pull request\'s or merge group\'s new code is judged against');
});
