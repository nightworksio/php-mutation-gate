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
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Plan\Cut;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Sample\Candidate;
use NightWorksIO\MutationGate\Core\Sample\Sample;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Mutator\Engine\Engine;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Fakes\CiPlanFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\TreeSourceFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\Mutators\PlusToMinus;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A plan over the project, in these settings, with these ports in place of the fakes. */
$plan = static fn(string $project, Mode $mode, Settings $settings, object ...$ports): Plan|CannotJudge => Planned::from(new Planning(
    Flows::adapters($project, [], ...$ports),
    $settings,
    Flows::setup(),
)->plan($mode, CoverageRun::of(WholeSuite::tests(), Workspace::coverage()), Cut::exactly(1), MatrixKind::FirstKiller));

/** A ledger on main whose newest proof of each unit killed all ten of its mutants, under keys no plan builds. */
$killedAll = static function (Unit ...$units): ProofStoreFake {
    $ledger = Ledger::empty();

    foreach ($units as $unit) {
        $mutants = Mutants::none();

        foreach (range(1, 10) as $line) {
            $mutants = $mutants->with(Mutant::of(
                MutantId::hash($unit->path(), 'LessThan', sprintf('%d', $line), 0),
                sprintf('%d', $line),
                Location::of($unit->path(), Line::of($line), Line::of($line)),
                Mutation::of('LessThan', MutatorFamily::Boundary, ''),
                MutantStatus::Killed,
                Unmeasured::duration(),
            ));
        }

        $ledger = $ledger->withProof(Proof::of(
            Digest::of(sprintf('older %s', $unit->path()->value())),
            $unit->path(),
            $mutants,
            Run::of('main', Moment::at('2026-10-01T10:00:00Z'), Digest::of(str_repeat('b', 64))),
        ));
    }

    $store = new ProofStoreFake();
    $store->write(Scope::branch('main'), $ledger);

    return $store;
};

$unitsOf = static fn(Plan|CannotJudge $plan): array => $plan instanceof Plan
    ? array_map(static fn(Unit $unit): string => $unit->path()->value(), [...[...$plan][0]->units()])
    : [$plan->why()];
$sampledOf = static fn(Plan|CannotJudge $plan): array => $plan instanceof Plan
    ? array_map(static fn(Unit $unit): string => $unit->path()->value(), [...$plan->considered()->sample()->sampled()])
    : [$plan->why()];
$unsampledOf = static fn(Plan|CannotJudge $plan): array => $plan instanceof Plan
    ? array_map(static fn(Candidate $left): array => [$left->unit()->path()->value(), $left->mutants()], [...$plan->considered()->sample()->unsampled()])
    : [$plan->why()];

it('draws no sample unless asked', function () use ($plan, $killedAll): void {
    $planned = $plan(Flows::project(), Mode::full(), Flows::settings(), $killedAll(Planned::money(), Planned::held()));

    expect($planned instanceof Plan ? $planned->considered()->sample() : $planned)->toEqual(Sample::none());
});

it('runs the units its draw takes, leaving out the rest with the mutants each is estimated to hold', function () use ($plan, $killedAll, $unitsOf, $sampledOf, $unsampledOf): void {
    $planned = $plan(Flows::project(), Mode::full()->sampled(), Flows::settings(Sampling::minUnits(1)), $killedAll(Planned::money(), Planned::held()));
    $sampled = $sampledOf($planned);
    $left = $unsampledOf($planned);

    expect(count($sampled))->toBe(1)
        ->and(count($left))->toBe(1)
        ->and($left[0][1])->toBe(10)
        ->and($unitsOf($planned))->toBe($sampled)
        ->and($planned instanceof Plan ? $planned->considered()->sample()->seed() : $planned)->toBe(Flows::HEAD);
});

it('draws from the seed the config sets, which decides which units run', function () use ($plan, $killedAll, $sampledOf): void {
    $drawn = static fn(string $seed): array => $sampledOf($plan(
        Flows::project(),
        Mode::full()->sampled(),
        Flows::settings(Sampling::minUnits(1), Sampling::seed($seed)),
        $killedAll(Planned::money(), Planned::held()),
    ));

    expect($drawn('abc'))->toBe(['src/Held.php'])
        ->and($drawn('abd'))->toBe(['src/Money.php']);
});

it('runs every unit of a tree held to 100, declared or by its baseline', function () use ($plan, $killedAll, $unitsOf, $sampledOf, $unsampledOf): void {
    $declared = $plan(
        Flows::project(),
        Mode::full()->sampled(),
        Flows::settings(Sampling::minUnits(1)),
        $killedAll(Planned::money(), Planned::held()),
        new TreeSourceFake(Trees::of(Tree::at(Path::of('src'), Floor::of(100), Package::at(Path::root())))),
    );
    $project = Flows::project();
    Scratch::write($project, 'mutation-gate.baseline.json', '{"format": 1, "trees": {"src": {"floor": 100}}}');
    $baseline = $plan($project, Mode::full()->sampled(), Flows::settings(Sampling::minUnits(1)), $killedAll(Planned::money(), Planned::held()));

    expect([$sampledOf($declared), $unsampledOf($declared), count($unitsOf($declared))])->toBe([[], [], 2])
        ->and([$sampledOf($baseline), $unsampledOf($baseline), count($unitsOf($baseline))])->toBe([[], [], 2]);
});

it('cannot draw a sample where the committed baseline cannot be read', function () use ($plan): void {
    $project = Flows::project();
    Scratch::write($project, 'mutation-gate.baseline.json', 'not json');

    expect($plan($project, Mode::full()->sampled(), Flows::settings()))->toBeInstanceOf(CannotJudge::class);
});

it('knows exactly, and so never samples, a unit holding new code, or one a change-scoped run reaches', function () use ($plan, $sampledOf): void {
    $project = Flows::project();
    Scratch::write($project, 'src/Sum.php', "<?php\n\nfunction sum(int \$a, int \$b): int\n{\n    return \$a + \$b;\n}\n");
    $files = [...Flows::FILES, 'src/Sum.php' => "<?php\n\nfunction sum(int \$a, int \$b): int\n{\n    return \$a + \$b;\n}\n"];
    $moneyChanged = new ChangeSourceFake(
        Revision::ref(Flows::MAIN),
        Changes::of(Change::modified(Path::of('src/Money.php'), Lines::of(Line::of(3)))),
        [Revision::workingTree()->name() => $files, 'base' => $files, Flows::MAIN => $files],
    );
    $pullRequest = new CiPlanFake(RunOn::at(Scope::pullRequest(7), Scope::branch('main')));
    $engine = Engine::with(new PlusToMinus());

    $full = $plan($project, Mode::full()->sampled(), Flows::settings(), $moneyChanged, $pullRequest, $engine);
    $scoped = $plan($project, Mode::since(Flows::MAIN)->sampled(), Flows::settings(), $moneyChanged, $engine);
    $unchanged = new ChangeSourceFake(
        Revision::ref(Flows::MAIN),
        Changes::none(),
        [Revision::workingTree()->name() => $files, 'base' => $files, Flows::MAIN => $files],
    );
    $whole = $plan($project, Mode::full()->sampled(), Flows::settings(), $unchanged, $engine);

    expect($sampledOf($full))->toBe(['src/Sum.php'])
        ->and($sampledOf($scoped))->toBe(['src/Sum.php'])
        ->and($sampledOf($whole))->toBe(['src/Sum.php', 'src/Money.php']);
});

it('draws nothing where the plan cannot tell a pull request\'s new code, which it refuses', function () use ($plan): void {
    $project = Flows::project();
    Scratch::write($project, 'mutation-gate.baseline.json', 'not json');
    $planned = $plan($project, Mode::full()->sampled(), Flows::settings(), new CiPlanFake(RunOn::at(Scope::pullRequest(7), Scope::branch('main'))));

    expect($planned instanceof CannotJudge ? $planned->why() : '')->toStartWith('A pull request\'s new code is judged against');
});
