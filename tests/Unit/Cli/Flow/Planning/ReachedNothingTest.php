<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\CommandLine;
use NightWorksIO\MutationGate\Cli\Config\Detected;
use NightWorksIO\MutationGate\Cli\Config\Effective;
use NightWorksIO\MutationGate\Cli\Config\Registered;
use NightWorksIO\MutationGate\Cli\Config\RunContext;
use NightWorksIO\MutationGate\Cli\Flow\DecidingConfig;
use NightWorksIO\MutationGate\Cli\Flow\Mode;
use NightWorksIO\MutationGate\Cli\Flow\Planning;
use NightWorksIO\MutationGate\Cli\Flow\Setup;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Config\Baseline;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\PullRequestNumber;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\ConfigReads;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Cut;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\StandsBecause;
use NightWorksIO\MutationGate\Core\Plan\Unchanged;
use NightWorksIO\MutationGate\Core\Proof\Digests;
use NightWorksIO\MutationGate\Core\Proof\LastRun;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\RunProfile;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\ScopeRuns;
use NightWorksIO\MutationGate\Core\Proof\Undigested;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Fakes\CiPlanFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\JudgedCommits;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** The commit #516 merged onto, which the default branch passed at REACHED_NOTHING_PASSED_AT. */
const REACHED_NOTHING_BASE = 'f5a494567695fb63aac663f91ef28178d1f1db24';

const REACHED_NOTHING_PASSED_AT = '2026-11-02T12:43:14Z';

/** Every path #516 changed, as its merge changed it from the commit it merged onto. */
$pullRequest516 = static fn(): Changes => Changes::of(
    Change::modified(Path::of('.gitattributes'), Lines::of(Line::of(1))),
    Change::modified(Path::of('.github/gates.json'), Lines::of(Line::of(455))),
    Change::added(Path::of('.github/scripts/line_cap.py'), Lines::of(Line::of(1))),
    Change::added(Path::of('.github/scripts/test_line_cap.py'), Lines::of(Line::of(1))),
    Change::modified(Path::of('.github/workflows/hygiene.yml'), Lines::of(Line::of(40))),
    Change::modified(Path::of('.github/workflows/scorecard.yml'), Lines::of(Line::of(30))),
);

/**
 * A plan of a pull request whose change since the base is this, made in this
 * mode, where the default branch's ledger holds a result of each unit at its
 * code from this instant, of a run of this kind, keyed as the plan keys it
 * where asked, and records the base as passed, and the pull request's own
 * ledger holds a newer result of the money file, or a last run, where asked;
 * with this port in place of its fake, and the config file changed from the
 * one it held before to the one it holds now, where given, at its path.
 */
$plan = static function (
    Changes $changes,
    Settings $settings,
    Mode|NotGiven $mode = new NotGiven(),
    string $resultsAt = REACHED_NOTHING_PASSED_AT,
    bool $keyed = false,
    string $own = '',
    RunProfile|NotGiven $profile = new NotGiven(),
    Narrowing|CiPlanFake|ConfigReads|NotGiven $port = new NotGiven(),
    string $configBefore = '',
    string $configNow = '',
    string $configFile = 'mutation-gate.json',
): Plan|CannotJudge {
    $project = Flows::project();
    $now = [...Flows::FILES, ...($configNow === '' ? [] : [$configFile => $configNow])];
    $before = [...Flows::FILES, ...($configBefore === '' ? [] : [$configFile => $configBefore])];
    $setup = $configNow === '' ? Flows::setup() : reachedNothingReading($project, $configFile);

    foreach ($now as $path => $contents) {
        Scratch::write($project, $path, $contents);
    }

    $planOf = static fn(Mode $mode, object ...$with): Plan|CannotJudge => Planned::from(new Planning(Flows::adapters($project, [], ...$with), $settings, $setup)
        ->plan($mode, CoverageRun::of(WholeSuite::tests(), Workspace::coverage()), Cut::bySize(600, Seconds::minutes(1), 20), MatrixKind::FirstKiller));
    $full = $planOf(Mode::full());
    $digests = $full instanceof Plan ? $full->digests() : Undigested::proof();
    $base = $full instanceof Plan ? $full->base() : Digest::of('none');
    $keyOf = static function (Unit $unit) use ($full, $keyed): Digest {
        $key = $full instanceof Plan ? $full->keys()->keyOf($unit->path()) : Digest::of('none');

        return $keyed && $key instanceof Digest ? $key : Digest::of(sprintf('%s on main', $unit->path()->value()));
    };
    $proof = static fn(Unit $unit, string $scope, string $at): Proof => Proof::of(
        $keyOf($unit),
        $unit->path(),
        Mutants::none(),
        Run::of($scope, Moment::at($at), $base)->profiled($profile instanceof RunProfile ? $profile : RunProfile::standard()),
    )->withInputs($digests instanceof Digests ? $digests->inputsOf($unit->path(), Paths::none()) : $digests);
    $store = new ProofStoreFake();
    $store->write(Scope::branch('main'), Ledger::empty()
        ->withProof($proof(Planned::money(), 'main', $resultsAt))
        ->withProof($proof(Planned::held(), 'main', $resultsAt))
        ->withRuns(ScopeRuns::none()->passing(
            Passed::of(Revision::ref(REACHED_NOTHING_BASE), 'mutation / verdict', 0)->passedAt(Instant::at(new DateTimeImmutable(REACHED_NOTHING_PASSED_AT))),
        )));
    $store->write(Scope::pullRequest(516), match ($own) {
        'result' => Ledger::empty()->withProof($proof(Planned::money(), 'pr', '2026-11-02T13:00:00Z')),
        'last run' => Ledger::empty()->withRuns(ScopeRuns::none()->lastRunAt(LastRun::of(JudgedCommits::of('last-run'), 'mutation / verdict', RunProfile::standard()))),
        default => Ledger::empty(),
    });
    $checkout = new ChangeSourceFake(
        Revision::ref(REACHED_NOTHING_BASE),
        $changes,
        [Revision::workingTree()->name() => $now, REACHED_NOTHING_BASE => $before, Flows::MAIN => $before, 'last-run' => $before],
    )->changedFrom(Revision::ref('last-run'), Changes::none())->alsoFrom(Revision::ref(Flows::MAIN));
    $ci = new CiPlanFake(RunOn::pullRequest(PullRequestNumber::of(516), Scope::branch('main'), Scope::branch('main')))
        ->runBy(Paths::of(Path::of('.github/workflows/ci.yml'), Path::of('.github/workflows/mutation-gate.yml')));

    return $planOf($mode instanceof Mode ? $mode : Mode::since(REACHED_NOTHING_BASE), ...[...$port instanceof NotGiven ? [] : [$port], $store, $checkout, $ci]);
};

/** The flows' setup, where the run reads the config file at this path in the project, as the command line reads it. */
function reachedNothingReading(string $project, string $file): Setup
{
    $setup = Flows::setup();

    return new Setup(
        Path::of($file),
        $setup->gate,
        $setup->installed,
        $setup->clock,
        $setup->memory,
        DecidingConfig::read(
            new Effective(
                $project,
                Registered::config(Extensions::of(Origin::of('nightworksio/mutation-gate')), static fn(): bool => true),
                new Detected(Directory::at($project), Directory::at(sprintf('%s/vendor', $project))),
                new DateTimeImmutable(Configs::NOW),
                new RunContext(Variables::of([])),
            ),
            CommandLine::nothing(),
            $project,
            Path::of($file),
        ),
        $setup->command,
    );
}

$reporting = static fn(): Settings => Flows::settings(Baseline::reportingImprovement());

it('replays #516: its change reaches no unit, so the plan cuts no shard, carries every unit, and the verdict the default branch passed stands', function () use ($plan, $pullRequest516, $reporting): void {
    $made = $plan($pullRequest516(), $reporting());
    $stands = $made instanceof Plan ? $made->briefing()->unchanged() : $made;

    expect($made instanceof Plan ? count($made) : $made)->toBe(0)
        ->and($made instanceof Plan ? [...$made->considered()->carried()] : $made)->toEqual([Planned::held(), Planned::money()])
        ->and($made instanceof Plan ? array_map(static fn(Reason $reason): string => $reason->text(), [...$made->considered()->reach()]) : $made)->toBe([
            '`.gitattributes` reaches nothing by itself.',
            '`.github/gates.json` reaches nothing by itself.',
            '`.github/scripts/line_cap.py` reaches nothing by itself.',
            '`.github/scripts/test_line_cap.py` reaches nothing by itself.',
            '`.github/workflows/hygiene.yml` reaches nothing by itself.',
            '`.github/workflows/scorecard.yml` reaches nothing by itself.',
        ])
        ->and($stands instanceof Unchanged ? [$stands->because(), $stands->base()->commit()->name()] : $stands)
        ->toBe([StandsBecause::NothingReached, REACHED_NOTHING_BASE]);
});

it('cuts no shard and judges what it carries where a later run left results, or a pull request must commit each raise with it', function (string $resultsAt, bool $reportsRaises) use ($plan, $pullRequest516, $reporting): void {
    $made = $plan($pullRequest516(), $reportsRaises ? $reporting() : Flows::settings(), resultsAt: $resultsAt);

    expect($made instanceof Plan ? [count($made), $made->briefing()->unchanged()] : $made)->toEqual([0, NotGiven::value()]);
})->with([
    'a later run' => ['2026-11-02T12:43:15Z', true],
    'raises required' => [REACHED_NOTHING_PASSED_AT, false],
]);

it('judges what it carries where a run is narrowed, though its change reaches no unit', function () use ($plan, $pullRequest516, $reporting): void {
    $made = $plan(
        $pullRequest516(),
        $reporting(),
        profile: RunProfile::standard()->securityOnly(),
        port: Narrowing::none()->toMutators(Mutators::named('security/HashEqualsToTrue')),
    );

    expect($made instanceof Plan ? [count($made), $made->briefing()->unchanged()] : $made)->toEqual([0, NotGiven::value()]);
});

it('stands on nothing where the change reaches a unit, which a shard runs', function () use ($plan, $reporting): void {
    $made = $plan(Changes::of(Change::modified(Path::of('src/Money.php'), Lines::of(Line::of(3)))), $reporting());

    expect($made instanceof Plan ? [count($made), $made->briefing()->unchanged()] : $made)->toEqual([1, NotGiven::value()]);
});

it('judges what it proves in a full run, which reads its change since no commit, though no unit is left to run', function () use ($plan, $pullRequest516, $reporting): void {
    $made = $plan($pullRequest516(), $reporting(), Mode::full(), keyed: true);

    expect($made instanceof Plan ? [count($made), count($made->considered()->proved()), $made->briefing()->unchanged()] : $made)
        ->toEqual([0, 2, NotGiven::value()]);
});

it('judges what it proves where its change reaches units earlier results prove, so no older verdict stands for them', function () use ($plan, $reporting): void {
    $made = $plan(Changes::of(Change::modified(Path::of('.github/workflows/ci.yml'), Lines::of(Line::of(1)))), $reporting(), keyed: true);

    expect($made instanceof Plan ? [count($made), count($made->considered()->proved()), $made->briefing()->unchanged()] : $made)
        ->toEqual([0, 2, NotGiven::value()]);
});

it('judges what it carries where the plan takes a result of the pull request\'s own, or reads the change since its own last run', function (Mode|NotGiven $mode, string $own) use ($plan, $pullRequest516, $reporting): void {
    $made = $plan($pullRequest516(), $reporting(), $mode, own: $own);

    expect($made instanceof Plan ? [count($made), $made->briefing()->unchanged()] : $made)->toEqual([0, NotGiven::value()]);
})->with([
    'its own result' => [fn(): NotGiven => new NotGiven(), 'result'],
    'since its last run' => [fn(): Mode => Mode::since(Mode::LAST_RUN), 'last run'],
]);

it('stands on a push to the default branch that reaches no unit, which commits no raise, though a pull request must', function () use ($plan, $pullRequest516): void {
    $made = $plan($pullRequest516(), Flows::settings(), port: Flows::ci());

    expect($made instanceof Plan ? [count($made), $made->briefing()->unchanged() instanceof Unchanged] : $made)->toBe([0, true]);
});

it('judges what it carries where the config moved only what judges, which reaches no unit but moves the verdict', function (string $before, string $now, string $file) use ($plan, $reporting): void {
    $made = $plan(
        Changes::of(Change::modified(Path::of($file), Lines::of(Line::of(1)))),
        $reporting(),
        configBefore: $before,
        configNow: $now,
        configFile: $file,
    );

    expect($made instanceof Plan ? [count($made), $made->briefing()->unchanged()] : $made)->toEqual([0, NotGiven::value()]);
})->with([
    'a raised tree floor' => ['{"runner": "fake", "trees": [{"path": "src", "floor": 80}]}', '{"runner": "fake", "trees": [{"path": "src", "floor": 90}]}', 'mutation-gate.json'],
    'a removed ignore' => [
        '{"runner": "fake", "ignores": {"entries": [{"mutant": "3f9a1c2b7d04", "reason": "Both branches build the same list", "expires": "2027-03-31"}]}}',
        '{"runner": "fake"}',
        'mutation-gate.json',
    ],
    'a tightened new-code floor' => ['{"runner": "fake", "newCode": {"floor": 80}}', '{"runner": "fake", "newCode": {"floor": 100}}', 'mutation-gate.json'],
    'a raised floor in a config the run names elsewhere' => ['{"runner": "fake", "trees": [{"path": "src", "floor": 80}]}', '{"runner": "fake", "trees": [{"path": "src", "floor": 90}]}', 'ci/gate.json'],
]);

it('judges what it proves where the config reads a file it cannot name, which every change reaches', function () use ($plan, $pullRequest516, $reporting): void {
    $made = $plan($pullRequest516(), $reporting(), keyed: true, port: ConfigReads::unnamed('The config reads a path it builds at run time.'));

    expect($made instanceof Plan ? [count($made), count($made->considered()->proved()), $made->briefing()->unchanged()] : $made)
        ->toEqual([0, 2, NotGiven::value()]);
});

it('judges what it proves where the config, found by its name or read beside it, changed', function (string $changed, ConfigReads $reads) use ($plan, $reporting): void {
    $made = $plan(
        Changes::of(Change::modified(Path::of($changed), Lines::of(Line::of(1)))),
        $reporting(),
        keyed: true,
        port: $reads,
    );

    expect($made instanceof Plan ? [count($made), count($made->considered()->proved()), $made->briefing()->unchanged()] : $made)
        ->toEqual([0, 2, NotGiven::value()]);
})->with([
    'the config the gate finds by its name' => ['mutation-gate.json', fn(): ConfigReads => ConfigReads::none()],
    'a file the config reads beside itself' => ['mutation-gate/floors.json', fn(): ConfigReads => ConfigReads::named(Path::of('mutation-gate/floors.json'))],
]);
