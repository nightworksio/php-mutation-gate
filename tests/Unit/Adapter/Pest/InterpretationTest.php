<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Ceiling;
use NightWorksIO\MutationGate\Adapter\Pest\CoverageFile;
use NightWorksIO\MutationGate\Adapter\Pest\Diff;
use NightWorksIO\MutationGate\Adapter\Pest\Interpretation;
use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Adapter\Pest\PestStatus;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Tests\Support\CoverageMaps;
use NightWorksIO\MutationGate\Tests\Support\PestRun;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Pest\Mutate\Mutators\Arithmetic\MinusToPlus;
use Pest\Mutate\Mutators\Arithmetic\PlusToMinus;

afterEach(function (): void {
    Scratch::sweep();
});

const INTERPRETED_PLUS = PlusToMinus::class;

const INTERPRETED_MINUS = MinusToPlus::class;

const INTERPRETED_SUMMARY = "\n  Mutations: 1 untested, 2 uncovered, 1 pending, 1 timeout, 1 tested\n";

/** A test Pest's filter can name, and one it cannot. */
const INTERPRETED_TESTS = ['P\Tests\MoneySpec::__pest_evaluable_it_adds', 'LegacySpec::decrements'];

/**
 * A project, the results file of a run in it, and the opening run's map beside it.
 *
 * @param array<positive-int, list<int<0, max>>> $money  the tests on each line of src/Money.php, by index
 * @param array<positive-int, list<int<0, max>>> $legacy the tests on each line of legacy/Legacy.php
 *
 * @return array{Project, string, string}
 */
function interpretedRun(array $money, array $legacy): array
{
    $root = (string) realpath(Scratch::directory());
    $project = Project::at($root, Paths::of(Path::of('tests')), Path::of('.mutation-gate'), Path::of('vendor'));
    $fresh = $project->freshResults();
    $results = is_string($fresh) ? $fresh : '';
    $long = sprintf('P\Tests\LongSpec::__pest_evaluable_%s', str_repeat('x', Ceiling::BYTES));
    CoverageMaps::write(
        sprintf('%s.coverage.php', $results),
        sprintf('%s/', $root),
        ['src/Money.php' => $money, 'legacy/Legacy.php' => $legacy],
        [...INTERPRETED_TESTS, $long],
        [],
    );

    return [$project, $results, $root];
}

/** The changes the runs below make, each removed line and the line that replaces it. */
const INTERPRETED_CHANGES = [
    'ab' => ['return $a + $b;', 'return $a - $b;', INTERPRETED_PLUS],
    'cd' => ['return $c + $d;', 'return $c - $d;', INTERPRETED_PLUS],
    'ef' => ['return $e + $f;', 'return $e - $f;', INTERPRETED_PLUS],
    'amount' => ['return $amount - 1;', 'return $amount + 1;', INTERPRETED_MINUS],
];

/** A mutant as the plugin plans it, at a file of the project and a line. */
$plan = static function (string $root, string $id, string $where, string $change): string {
    [$file, $line] = explode(':', $where);
    [$removed, $added, $mutator] = INTERPRETED_CHANGES[$change];

    return PestRun::planned($id, sprintf('%s/%s', $root, $file), (int) $line, $mutator, $removed, $added);
};

/** The gate's record of a planned mutant: where, what, its status and how long it ran. */
$mutant = static function (
    string $id,
    string $where,
    string $change,
    MutantStatus $status,
    float $seconds = 0.0,
    int $occurrence = 0,
): Mutant {
    [$file, $line] = explode(':', $where);
    [$removed, $added, $mutator] = INTERPRETED_CHANGES[$change];
    $diff = Diff::fromPest(sprintf("\n  <fg=red>-        %s</>\n  <fg=green>+        %s</>\n", $removed, $added));
    $path = Path::of($file);

    return Mutant::of(
        MutantId::hash($path, $mutator, $diff, $occurrence),
        $id,
        Location::of($path, Line::of((int) $line), Line::of((int) $line)),
        Mutation::of($mutator, MutatorFamily::Arithmetic, $diff),
        $status,
        $seconds > 0.0 ? Seconds::of($seconds) : Unmeasured::duration(),
    );
};

/**
 * What the plugin writes of a run with six mutants, one of each status, one of them covered by a test Pest cannot name,
 * each finished in the order it was planned, as the plugin writes them.
 *
 * @return list<array<string, mixed>>
 */
$six = static fn(string $root): array => [
    $plan($root, 'n2', 'src/Money.php:21', 'amount'),
    $plan($root, 'n1', 'src/Money.php:11', 'ab'),
    $plan($root, 'n3', 'src/Money.php:40', 'ab'),
    $plan($root, 'n4', 'src/Money.php:50', 'cd'),
    $plan($root, 'n5', 'legacy/Legacy.php:11', 'amount'),
    $plan($root, 'n6', 'src/Money.php:60', 'ef'),
    PestRun::made(6),
    PestRun::outcome('n1', PestStatus::Tested),
    PestRun::killed('n1', INTERPRETED_TESTS[0]),
    PestRun::finished('n2', PestStatus::Uncovered, 0.0),
    PestRun::finished('n1', PestStatus::Tested, 0.25),
    PestRun::finished('n3', PestStatus::Untested, 0.5),
    PestRun::finished('n4', PestStatus::Timeout, 5.0),
    PestRun::finished('n5', PestStatus::Uncovered, 0.0),
    PestRun::finished('n6', PestStatus::None, 0.0),
    PestRun::end(),
];

/** The interpretation of a run in a project, with pest:patch off. */
$read = static fn(Project $project, Ran $ran, string $results): MutationResult|CannotJudge
    => new Interpretation($project, Patching::off(), MemoryCap::standard())->of($ran, $results, CoverageFile::at(Recorder::coverageBeside($results)));

it('reads a finished run by file and line, with a killer and a timeout\'s limit', function () use ($mutant, $six, $read): void {
    [$project, $results, $root] = interpretedRun([11 => [0], 21 => [], 40 => [0]], [11 => [1]]);
    PestRun::write($results, $six($root));
    $unselected = Reason::that(
        "Pest's --filter cannot select LegacySpec::decrements, so Pest cannot run it against this mutant.",
    );

    expect($read($project, Ran::finished(succeeded: true, output: INTERPRETED_SUMMARY), $results))
        ->toEqual(MutationResult::of(Mutants::of(
            $mutant('n5', 'legacy/Legacy.php:11', 'amount', MutantStatus::Unjudged)->because($unselected),
            $mutant('n1', 'src/Money.php:11', 'ab', MutantStatus::Killed, 0.25)
                ->killedBy(TestIds::of(TestId::of(INTERPRETED_TESTS[0]))),
            $mutant('n2', 'src/Money.php:21', 'amount', MutantStatus::Uncovered),
            $mutant('n3', 'src/Money.php:40', 'ab', MutantStatus::Survived, 0.5, 1),
            $mutant('n4', 'src/Money.php:50', 'cd', MutantStatus::TimedOut, 5.0)->withLimit(Seconds::of(6.0)),
            $mutant('n6', 'src/Money.php:60', 'ef', MutantStatus::Unjudged),
        ), 0));
});

it('keeps what a stopped run judged, with limits, and leaves the rest', function () use ($mutant, $plan, $read): void {
    [$project, $results, $root] = interpretedRun([11 => [0]], []);
    PestRun::write($results, [
        $plan($root, 'n1', 'src/Money.php:11', 'ab'),
        $plan($root, 'n2', 'src/Money.php:12', 'cd'),
        $plan($root, 'n3', 'src/Money.php:13', 'ef'),
        PestRun::made(3),
        PestRun::outcome('n1', PestStatus::Tested),
        PestRun::outcome('n2', PestStatus::Timeout),
    ]);

    expect($read($project, Ran::stopped('half a run'), $results))->toEqual(MutationResult::of(Mutants::of(
        $mutant('n1', 'src/Money.php:11', 'ab', MutantStatus::Killed),
        $mutant('n2', 'src/Money.php:12', 'cd', MutantStatus::TimedOut)->withLimit(Seconds::of(6.0)),
        $mutant('n3', 'src/Money.php:13', 'ef', MutantStatus::Unjudged),
    ), 0));
});

it('cannot judge a run stopped while the plugin wrote the mutants Pest made', function () use ($plan, $read): void {
    [$project, $results, $root] = interpretedRun([11 => [0]], []);
    PestRun::write($results, [$plan($root, 'n1', 'src/Money.php:11', 'ab')]);

    expect($read($project, Ran::stopped('half a run'), $results))
        ->toEqual(CannotJudge::because('Pest was stopped at its deadline before it had made its mutants.'));
});

it('cannot judge a run stopped at its deadline before Pest made its mutants', function () use ($read): void {
    [$project, $results] = interpretedRun([], []);
    PestRun::write($results, []);

    expect($read($project, Ran::stopped('opening'), $results))
        ->toEqual(CannotJudge::because('Pest was stopped at its deadline before it had made its mutants.'));
});

it('cannot judge a run that failed, with what Pest said', function () use ($read): void {
    [$project, $results] = interpretedRun([], []);
    PestRun::write($results, []);

    expect($read($project, Ran::finished(succeeded: false, output: 'Tests: 1 failed'), $results))
        ->toEqual(CannotJudge::because("Pest's mutation run failed. Pest said:\nTests: 1 failed"));
});

it('reads a kill whose own process ran out of exactly the gate\'s cap as out of memory, with the cap, and no other', function () use (
    $mutant,
    $plan,
): void {
    [$project, $results, $root] = interpretedRun([11 => [0], 12 => [0], 13 => [0]], []);
    $cap = MemoryCap::of(64, MemoryUnit::Megabytes);
    PestRun::write($results, [
        $plan($root, 'n1', 'src/Money.php:11', 'ab'),
        $plan($root, 'n2', 'src/Money.php:12', 'cd'),
        $plan($root, 'n3', 'src/Money.php:13', 'ef'),
        PestRun::made(3),
        PestRun::exhausted('n1', MemoryCap::of(67108864, MemoryUnit::Bytes)),
        PestRun::exhausted('n2', MemoryCap::of(128, MemoryUnit::Megabytes)),
        PestRun::exhausted('n3', MemoryCap::of(64, MemoryUnit::Megabytes)),
        PestRun::finished('n1', PestStatus::Tested, 0.25),
        PestRun::finished('n2', PestStatus::Tested, 0.25),
        PestRun::finished('n3', PestStatus::Untested, 0.25),
        PestRun::end(),
    ]);
    $ran = Ran::finished(succeeded: true, output: "\n  Mutations: 1 untested, 2 tested\n");

    expect(new Interpretation($project, Patching::off(), $cap)->of($ran, $results, CoverageFile::at(Recorder::coverageBeside($results))))
        ->toEqual(MutationResult::of(Mutants::of(
            $mutant('n1', 'src/Money.php:11', 'ab', MutantStatus::OutOfMemory, 0.25)->withLimit($cap),
            $mutant('n2', 'src/Money.php:12', 'cd', MutantStatus::Killed, 0.25),
            $mutant('n3', 'src/Money.php:13', 'ef', MutantStatus::Survived, 0.25),
        ), 0));
});

it('cannot judge a run whose own process ran out of the gate\'s cap, and says to raise it', function (): void {
    [$project, $results] = interpretedRun([], []);
    PestRun::write($results, []);
    $said = 'PHP Fatal error:  Allowed memory size of 67108864 bytes exhausted (tried to allocate 4096 bytes)';
    $ran = Ran::finished(succeeded: false, output: $said);
    $coverage = CoverageFile::at(Recorder::coverageBeside($results));

    expect(new Interpretation($project, Patching::off(), MemoryCap::of(64, MemoryUnit::Megabytes))->of($ran, $results, $coverage))
        ->toEqual(CannotJudge::because(sprintf(
            "Pest ran out of the 64M memory cap in its own process, so the run did not finish. %s Pest said:\n%s",
            'Raise runner.memory; doctor --measure says what the suite needs.',
            $said,
        )))
        ->and(new Interpretation($project, Patching::off(), MemoryCap::of(128, MemoryUnit::Megabytes))->of($ran, $results, $coverage))
        ->toEqual(CannotJudge::because(sprintf("Pest's mutation run failed. Pest said:\n%s", $said)));
});

it('judges a run that reached its end though a minimum score failed it', function () use ($six, $read): void {
    [$project, $results, $root] = interpretedRun([11 => [0], 21 => [], 40 => [0]], [11 => [1]]);
    PestRun::write($results, $six($root));

    $failed = $read($project, Ran::finished(succeeded: false, output: INTERPRETED_SUMMARY), $results);

    expect($failed)->toBeInstanceOf(MutationResult::class)
        ->and($failed)->toEqual($read($project, Ran::finished(succeeded: true, output: INTERPRETED_SUMMARY), $results));
});

it('cannot judge a run whose plugin wrote nothing', function () use ($read): void {
    [$project, $results] = interpretedRun([], []);

    expect($read($project, Ran::finished(succeeded: true, output: INTERPRETED_SUMMARY), $results))
        ->toEqual(CannotJudge::because(sprintf(
            'Pest wrote no results to %s. Is pestphp/pest-plugin allowed to run in composer.json?',
            $results,
        )));
});

it('cannot judge a finished run that printed no summary', function () use ($six, $read): void {
    [$project, $results, $root] = interpretedRun([], []);
    PestRun::write($results, $six($root));

    $ran = Ran::finished(succeeded: true, output: 'no summary');

    expect($read($project, $ran, $results))->toEqual(CannotJudge::because(
        "Pest printed no Mutations: summary, so its mutation run did not finish. Pest said:\nno summary",
    ));
});

it('cannot judge records that do not add up to Pest\'s own summary', function () use ($six, $read): void {
    [$project, $results, $root] = interpretedRun([], []);
    PestRun::write($results, $six($root));
    $summary = "  Mutations: 2 untested, 2 uncovered, 1 pending, 1 timeout, 0 tested\n";

    expect($read($project, Ran::finished(succeeded: true, output: $summary), $results))->toEqual(CannotJudge::because(
        sprintf("Pest's records of its mutants do not add up to its summary. Pest said:\n%s", $summary),
    ));
});

it('judges each of the mutants Pest gives one id, as two changes that leave the same source share it', function () use ($mutant, $plan, $read): void {
    [$project, $results, $root] = interpretedRun([11 => [0]], []);
    PestRun::write($results, [
        $plan($root, 'same', 'src/Money.php:11', 'ab'),
        $plan($root, 'same', 'src/Money.php:11', 'ab'),
        PestRun::made(2),
        PestRun::killed('same', INTERPRETED_TESTS[0]),
        PestRun::finished('same', PestStatus::Tested, 0.25),
        PestRun::finished('same', PestStatus::Untested, 0.5),
        PestRun::end(),
    ]);

    expect($read($project, Ran::finished(succeeded: true, output: '  Mutations: 1 untested, 1 tested'), $results))
        ->toEqual(MutationResult::of(Mutants::of(
            $mutant('same', 'src/Money.php:11', 'ab', MutantStatus::Killed, 0.25)
                ->killedBy(TestIds::of(TestId::of(INTERPRETED_TESTS[0]))),
            $mutant('same', 'src/Money.php:11', 'ab', MutantStatus::Survived, 0.5, 1),
        ), 0));
});

it('cannot judge a run without the opening run\'s map', function () use ($six, $read): void {
    [$project, $results, $root] = interpretedRun([], []);
    PestRun::write($results, $six($root));
    unlink(sprintf('%s.coverage.php', $results));

    $ran = Ran::finished(succeeded: true, output: INTERPRETED_SUMMARY);

    expect($read($project, $ran, $results))->toEqual(CannotJudge::because(
        sprintf('There is no coverage map at %s.coverage.php, so no test runs any line.', $results),
    ));
});

it('cannot judge a filter too long for Pest unpatched', function () use ($mutant, $plan): void {
    [$project, $results, $root] = interpretedRun([11 => [2, 1]], []);
    PestRun::write($results, [
        $plan($root, 'n1', 'src/Money.php:11', 'ab'),
        PestRun::made(1),
        PestRun::finished('n1', PestStatus::Tested, 0.25),
        PestRun::end(),
    ]);
    $ran = Ran::finished(succeeded: true, output: 'Mutations: 1 tested');
    $patched = new Interpretation($project, Patching::on(Group::named('mutation-canary')), MemoryCap::standard());

    expect(new Interpretation($project, Patching::off(), MemoryCap::standard())->of($ran, $results, CoverageFile::at(Recorder::coverageBeside($results))))->toEqual(CannotJudge::because(
        'Pest cannot pass the filter of the 2 tests covering src/Money.php:11. Turn on pest.patch.',
    ))->and($patched->of($ran, $results, CoverageFile::at(Recorder::coverageBeside($results))))->toEqual(MutationResult::of(Mutants::of(
        $mutant('n1', 'src/Money.php:11', 'ab', MutantStatus::Killed, 0.25),
    ), 0));
});

it('leaves a mutant it made before unjudged, keeping all but its status', function () use ($mutant): void {
    $killed = $mutant('n1', 'src/Money.php:11', 'ab', MutantStatus::Killed, 0.25);

    expect(Interpretation::unjudged($killed, Reason::that('Gone.')))->toEqual(
        $mutant('n1', 'src/Money.php:11', 'ab', MutantStatus::Unjudged, 0.25)->because(Reason::that('Gone.')),
    );
});
