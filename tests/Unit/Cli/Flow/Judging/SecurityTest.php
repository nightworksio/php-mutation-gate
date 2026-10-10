<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Cli\Flow\Judged;
use NightWorksIO\MutationGate\Cli\Flow\Judging;
use NightWorksIO\MutationGate\Config\Floor as NewCodeFloor;
use NightWorksIO\MutationGate\Config\Gate;
use NightWorksIO\MutationGate\Config\Ignore;
use NightWorksIO\MutationGate\Config\Report;
use NightWorksIO\MutationGate\Config\Runner as ConfiguredRunner;
use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\Baseline\BaselineFile;
use NightWorksIO\MutationGate\Core\Baseline\Entry;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Mutant\NamedMutators;
use NightWorksIO\MutationGate\Core\Plan\Considered;
use NightWorksIO\MutationGate\Core\Proof\Digests;
use NightWorksIO\MutationGate\Core\Proof\Inputs;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Unraised;
use NightWorksIO\MutationGate\Core\Test\DeclaredSuite;
use NightWorksIO\MutationGate\Core\Test\SuiteName;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\JudgedKill;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\ReporterFake;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\JudgingRuns;
use NightWorksIO\MutationGate\Tests\Support\LedgerRead;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

$tree = JudgingRuns::tree(...);
$reporting = JudgingRuns::reporting(...);
$judged = JudgingRuns::judged(...);

it('leaves the survivors the config ignores out of the score, and names an ignore that ends soon or has ended', function () use (
    $tree,
    $reporting,
    $judged,
): void {
    $verdict = JudgingRuns::verdictOf($judged(
        Planned::twoShards(),
        Flows::adapters(Flows::project(), [], $tree(Floor::of(50))),
        JudgingRuns::settings(
            Ignore::mutant('49e02fb39669', 'The bound is never reached', '2026-10-10'),
            Ignore::mutator('arithmetic', 'src/Held.php', 'Both sums are the same'),
            Ignore::mutant('95e61bd8bf62', 'Covered by the integration suite', '2026-09-29'),
        ),
        $reporting(new ReporterFake()),
    ));

    expect($verdict->judgement())->toBe(Judgement::Passed)
        ->and($verdict->trees()->mutants()->counts()->number(MutantJudgement::Ignored))->toBe(2)
        ->and($verdict->trees()->mutants()->counts()->number(MutantJudgement::Uncovered))->toBe(1)
        ->and(JudgingRuns::texts($verdict->warnings()))->toBe([
            'The ignore of 95e61bd8bf62 expired on 2026-09-29, so its mutants count again.',
            'The ignore of 49e02fb39669 expires on 2026-10-10.',
        ]);
});

it('fails a run that judged every unit on an ignore that names no mutant it leaves out', function () use (
    $tree,
    $reporting,
    $judged,
): void {
    $judgement = $judged(
        Planned::twoShards(),
        Flows::adapters(Flows::project(), [], $tree(Floor::of(0))),
        JudgingRuns::settings(Ignore::mutator('MethodCallRemoval', 'src/Log/**', 'Logged elsewhere')),
        $reporting(new ReporterFake()),
    );

    expect(JudgingRuns::texts(JudgingRuns::verdictOf($judgement)->failures()))->toBe([
        'The ignore of MethodCallRemoval in src/Log/** names no mutant it could leave out, in a run that judged every unit: remove it.',
    ])
        ->and($judgement instanceof Judged ? $judgement->exitCode() : $judgement)->toBe(ExitCode::Failed);
});

it('stops a CI run on a security set held to no floor, and hands over its measured floor', function () use ($tree, $reporting, $judged): void {
    $judgement = $judged(
        Planned::twoShards(),
        Flows::adapters(Flows::project(), ['CI' => 'true'], $tree(Floor::of(10)), NamedMutators::of('Plus')),
        JudgingRuns::settings(),
        $reporting(new ReporterFake()),
    );
    $verdict = JudgingRuns::verdictOf($judgement);
    $sets = [...$verdict->sets()->security()];

    expect($judgement instanceof Judged ? $judgement->exitCode() : $judgement)->toBe(ExitCode::CannotJudge)
        ->and(count($sets))->toBe(1)
        ->and($sets[0]->mutants())->toHaveCount(2)
        ->and(JudgingRuns::texts($verdict->failures()))->toBe([
            <<<'SAID'
                The security set of . has no floor: neither security.floor nor the package's securityFloor
                declares one, and the baseline holds none.
                A security set is never held to no floor. Run mutation-gate baseline --write and commit mutation-gate.baseline.json.
                SAID,
            sprintf(
                "The baseline this run measured, ready to commit as mutation-gate.baseline.json:\n%s",
                BaselineFile::encode(
                    Baseline::of(Entry::of(Path::of('src'), Floor::of(40)))->withSecurity(Entry::of(Path::root(), Floor::of(50))),
                ),
            ),
        ]);
});

it('holds only the security sets in a run of the security mutators alone, exempting each tree and recording no pass', function () use (
    $tree,
    $reporting,
    $judged,
): void {
    $store = new ProofStoreFake();
    $settings = Configs::built(Gate::configure()
        ->runner(ConfiguredRunner::uses('fake'))
        ->reporting(Report::uses('recorded'))
        ->security(NewCodeFloor::of(0)));
    $verdict = JudgingRuns::verdictOf($judged(
        Planned::twoShards(),
        Flows::adapters(Flows::project(), [], $store, $tree(Floor::of(100)), NamedMutators::of('Plus'), Narrowing::none()->toMutators(Mutators::named('Plus'))),
        $settings,
        $reporting(new ReporterFake()),
    ));
    $trees = [...$verdict->trees()];
    $mutators = array_map(static fn(JudgedMutant|JudgedKill $judged): string => $judged->mutant()->mutator(), [...$trees[0]->mutants()]);

    expect($verdict->judgement())->toBe(Judgement::Passed)
        ->and($trees[0]->floor())->toEqual(Exempt::because('--security judges only the security sets'))
        ->and($mutators)->toBe(['Plus', 'Plus'])
        ->and([...$verdict->sets()->security()][0]->mutants())->toHaveCount(2)
        ->and([...$verdict->sets()->newCode()])->toBe([])
        ->and(LedgerRead::ledger($store->read(Scope::branch('main')))->runs()->passed())->toBeInstanceOf(CannotTell::class);
});

it('holds no floor in a run of one suite\'s tests alone, exempting each tree and security set and recording no pass', function () use (
    $tree,
    $reporting,
    $judged,
): void {
    $store = new ProofStoreFake();
    $settings = Configs::built(Gate::configure()
        ->runner(ConfiguredRunner::uses('fake'))
        ->reporting(Report::uses('recorded'))
        ->security(NewCodeFloor::of(100))
        ->newCode(NewCodeFloor::of(100)));
    $judgement = $judged(
        Planned::twoShards()->on(RunOn::at(Scope::pullRequest(7), Scope::branch('main'))),
        Flows::adapters(Flows::project(), ['CI' => 'true'], $store, $tree(Floor::of(100)), NamedMutators::of('Plus'), Narrowing::none()->toSuite(SuiteName::of('unit'))),
        $settings,
        $reporting(new ReporterFake()),
    );
    $verdict = JudgingRuns::verdictOf($judgement);
    $sets = [...$verdict->sets()->security()];

    expect($verdict->judgement())->toBe(Judgement::Passed)
        ->and($judgement instanceof Judged ? $judgement->exitCode() : $judgement)->toBe(ExitCode::Passed)
        ->and([...$verdict->trees()][0]->floor())->toEqual(Exempt::because('--suite judges no floor'))
        ->and($sets[0]->floor())->toEqual(Exempt::because('--suite judges no floor'))
        ->and($sets[0]->raised())->toBeInstanceOf(Unraised::class)
        ->and([...$verdict->sets()->newCode()])->toBe([])
        ->and(LedgerRead::ledger($store->read(Scope::pullRequest(7)))->runs()->passed())->toBeInstanceOf(CannotTell::class);
});

it('holds the security sets in a run of one suite\'s tests and the security mutators alone', function () use (
    $tree,
    $reporting,
    $judged,
): void {
    $settings = Configs::built(Gate::configure()
        ->runner(ConfiguredRunner::uses('fake'))
        ->reporting(Report::uses('recorded'))
        ->security(NewCodeFloor::of(60)));
    $narrowing = Narrowing::none()->toMutators(Mutators::named('Plus'))->toSuite(SuiteName::of('unit'));
    $verdict = JudgingRuns::verdictOf($judged(
        Planned::twoShards(),
        Flows::adapters(Flows::project(), [], $tree(Floor::of(100)), NamedMutators::of('Plus'), $narrowing),
        $settings,
        $reporting(new ReporterFake()),
    ));
    $sets = [...$verdict->sets()->security()];

    expect($verdict->judgement())->toBe(Judgement::Failed)
        ->and([...$verdict->trees()][0]->floor())->toEqual(Exempt::because('--security judges only the security sets'))
        ->and($sets[0]->floor())->toEqual(Floor::of(60))
        ->and($sets[0]->judgement())->toBe(Judgement::Failed);
});

it('warns of a security set held to no floor outside CI', function () use ($tree, $reporting, $judged): void {
    $verdict = JudgingRuns::verdictOf($judged(
        Planned::twoShards(),
        Flows::adapters(Flows::project(), [], $tree(Floor::of(10)), NamedMutators::of('Plus')),
        JudgingRuns::settings(),
        $reporting(new ReporterFake()),
    ));

    expect(JudgingRuns::texts($verdict->warnings()))
        ->toContain('The security set of . has no floor yet. Run mutation-gate baseline --write and commit mutation-gate.baseline.json.');
});

it('fails a security set below the floor security.floor declares, though its tree passes', function () use ($tree, $reporting, $judged): void {
    $settings = Configs::built(Gate::configure()
        ->runner(ConfiguredRunner::uses('fake'))
        ->reporting(Report::uses('recorded'))
        ->security(NewCodeFloor::of(60)));
    $verdict = JudgingRuns::verdictOf($judged(
        Planned::twoShards(),
        Flows::adapters(Flows::project(), [], $tree(Floor::of(10)), NamedMutators::of('Plus')),
        $settings,
        $reporting(new ReporterFake()),
    ));
    $sets = [...$verdict->sets()->security()];

    expect($sets[0]->judgement())->toBe(Judgement::Failed)
        ->and([...$verdict->trees()][0]->judgement())->toBe(Judgement::Passed)
        ->and($verdict->judgement())->toBe(Judgement::Failed)
        ->and(JudgingRuns::texts($verdict->warnings()))->not->toContain(
            'The security set of . has no floor yet. Run mutation-gate baseline --write and commit mutation-gate.baseline.json.',
        );
});

it('fails a pull request until a raised security floor is committed with it', function () use ($tree, $reporting, $judged): void {
    $project = Flows::project();
    $committed = Baseline::of(Entry::of(Path::of('src'), Floor::of(40)))->withSecurity(Entry::of(Path::root(), Floor::of(40)));
    Scratch::write($project, 'mutation-gate.baseline.json', BaselineFile::encode($committed));
    $changes = new ChangeSourceFake(Revision::ref('base'), Changes::none(), [
        Revision::workingTree()->name() => Flows::FILES,
        'refs/remotes/origin/main' => ['mutation-gate.baseline.json' => BaselineFile::encode($committed)],
    ]);

    $verdict = JudgingRuns::verdictOf($judged(
        Planned::twoShards()->on(RunOn::at(Scope::pullRequest(7), Scope::branch('main'))),
        Flows::adapters($project, [], $tree(Floor::of(30)), $changes, NamedMutators::of('Plus')),
        JudgingRuns::settings(NewCodeFloor::of(0)),
        $reporting(new ReporterFake()),
    ));

    expect(JudgingRuns::texts($verdict->failures()))->toBe([<<<'SAID'
        The security set of . scored 50, above the floor of 40 it was held to.
        Commit the raised floor with this change: run mutation-gate baseline --write and commit mutation-gate.baseline.json.
        SAID]);
});

it('groups the verdict\'s tests into the suites the PHPUnit config declares, and into none without one', function () use (
    $tree,
    $reporting,
    $judged,
): void {
    $project = Flows::project();
    Scratch::write($project, 'phpunit.xml', <<<'XML'
        <?xml version="1.0"?>
        <phpunit>
            <testsuites>
                <testsuite name="Unit"><directory>tests/Unit</directory></testsuite>
                <testsuite name="Feature"><directory>tests/Feature</directory></testsuite>
            </testsuites>
        </phpunit>
        XML);
    $suites = static fn(string $at): array => array_map(
        static fn(DeclaredSuite $suite): string => $suite->name(),
        iterator_to_array(JudgingRuns::verdictOf($judged(
            Planned::twoShards(),
            Flows::adapters($at, [], $tree(Floor::of(0))),
            JudgingRuns::settings(),
            $reporting(new ReporterFake()),
        ))->matrix()->suites(), preserve_keys: false),
    );

    expect($suites($project))->toBe(['Unit', 'Feature'])
        ->and($suites(Flows::project()))->toBe([]);
});

it('checks, in a run of the security mutators alone, only the ignores of the mutators it made mutants with', function () use (
    $tree,
    $reporting,
    $judged,
): void {
    $failures = static fn(Ignore $ignore): array => JudgingRuns::texts(JudgingRuns::verdictOf($judged(
        Planned::twoShards(),
        Flows::adapters(Flows::project(), [], $tree(Floor::of(0)), NamedMutators::of('Plus'), Narrowing::none()->toMutators(Mutators::named('Plus'))),
        JudgingRuns::settings($ignore),
        $reporting(new ReporterFake()),
    ))->failures());

    expect($failures(Ignore::mutator('MethodCallRemoval', 'src/Log/**', 'Logged elsewhere')))->toBe([])
        ->and($failures(Ignore::mutator('Plus', 'src/Log/**', 'Logged elsewhere')))->toBe([
            'The ignore of Plus in src/Log/** names no mutant it could leave out, in a run that judged every unit: remove it.',
        ]);
});

it('takes the run\'s own result for a unit that carries its own alone, however newer the default branch\'s, and cannot judge it where that result is no longer of the code', function (string $source, string $vanished) use (
    $tree,
    $reporting,
): void {
    $project = Flows::project();
    $store = new ProofStoreFake();
    $mutation = Digest::sha256Of('mutation');
    $plan = Planned::of()
        ->on(RunOn::at(Scope::pullRequest(7), Scope::branch('main')))
        ->digesting(Digests::of($mutation)->withSource(Path::of('src/Held.php'), Digest::sha256Of('held now')))
        ->considering(
            Considered::everything()
                ->proving(Units::of(Planned::money()))
                ->carrying(Units::of(Planned::held()))
                ->carryingOwn(Units::of(Planned::held())),
        );
    $proofAt = static fn(string $key, string $file, string $at): Proof => Proof::of(
        Digest::sha256Of($key),
        Path::of($file),
        Flows::mutantsOf($file),
        Run::of('github:1/1', Moment::at($at), $plan->base()),
    );
    $store->write(Scope::branch('main'), Ledger::empty()
        ->withProof($proofAt('money', 'src/Money.php', '2026-09-29T12:00:00Z'))
        ->withProof($proofAt('held on main', 'src/Held.php', '2026-10-03T12:00:00Z')
            ->withInputs(Inputs::of(Digest::sha256Of('held on main'), $mutation))));
    $store->write(Scope::pullRequest(7), Ledger::empty()->withProof(
        $proofAt('held', 'src/Held.php', '2026-10-01T12:00:00Z')->withInputs(Inputs::of(Digest::sha256Of($source), $mutation)),
    ));

    $judgement = new Judging(
        Flows::adapters($project, [], $store, $tree(Floor::of(40))),
        JudgingRuns::settings(),
        Flows::setup(),
        $reporting(new ReporterFake()),
    )->verdict($plan, JudgingRuns::noResults($project));

    expect($vanished === ''
        ? LedgerRead::ledger($store->read(Scope::pullRequest(7)))->runs()->passed()
        : $judgement)->toEqual($vanished === ''
            ? Passed::of(Revision::ref(Flows::HEAD), 'mutation / verdict', 1)->passedAt(Instant::at(new DateTimeImmutable(Configs::NOW)))
            : CannotJudge::because(sprintf(<<<'SAID'
                The ledger no longer holds a proof the plan took, so these units cannot be judged:
                %s
                A proof pruned, or a cache replaced, between the plan and the verdict does this. Plan the run again.
                SAID, $vanished)));
})->with([
    'its own result of the code as it is' => ['held now', ''],
    'its own result of other code, as a race leaves it' => ['held before', 'src/Held.php'],
]);
