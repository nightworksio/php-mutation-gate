<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\Invocation;
use NightWorksIO\MutationGate\Adapter\Infection\JUnit;
use NightWorksIO\MutationGate\Adapter\Infection\Limits;
use NightWorksIO\MutationGate\Adapter\Infection\Project;
use NightWorksIO\MutationGate\Adapter\Infection\Ran;
use NightWorksIO\MutationGate\Adapter\Infection\Results;
use NightWorksIO\MutationGate\Adapter\Infection\TextLog;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\Unreported;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Tests\Support\InfectionRun;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A project whose MoneyTest takes half a second and covers line 11 of src/Money.php. */
function resultsProject(): Project
{
    return Project::at(Root::of(Scratch::directory()), Paths::none(), Path::of('.gate'));
}

function resultsLimits(Project $project): Limits
{
    InfectionRun::coverage(sprintf('%s/coverage', $project->root()), $project->root(), [], ['Tests\MoneyTest' => 0.5], []);
    $junit = JUnit::at(DiskPath::of(sprintf('%s/coverage/junit.xml', $project->root())));
    $map = CoverageMap::empty()->covered(Path::of('src/Money.php'), Line::of(11), TestId::of('Tests\MoneyTest::adds'));

    return Limits::of($map, $junit instanceof JUnit ? $junit : throw new RuntimeException('no JUnit'), Seconds::of(10.0));
}

/**
 * @param array<string, list<array<string, mixed>>>                                     $lists
 * @param array<string, int>                                                            $counts
 * @param array<string, list<array{string, int, string, string, string, string}>> $text
 */
function resultsRead(Project $project, array $lists, bool $allowed = false, array $counts = [], array $text = []): MutationResult|CannotJudge
{
    InfectionRun::log($project->own(Invocation::JSON), $lists, $counts);
    InfectionRun::text($project->own(Invocation::TEXT), $text);

    return Results::read(
        $project,
        Ran::finished(succeeded: false, output: 'exit 1'),
        TextLog::at($project->own(Invocation::TEXT)),
        resultsLimits($project),
        $allowed,
    );
}

/** @return array<string, mixed> */
function resultsMutant(Project $project, string $mutator, string $file, int $line, string $removed, string $added): array
{
    return InfectionRun::entry($mutator, sprintf('%s/%s', $project->root(), $file), $line, $removed, $added);
}

it('reads every list of the log as the gate\'s status, by file and then by line, whatever Infection\'s exit code', function (): void {
    $at = resultsProject();
    $diff = InfectionRun::diff('return $a + $b;', 'return $a - $b;');
    $result = resultsRead($at, [
        'escaped' => [resultsMutant($at, 'GreaterThan', 'src/Money.php', 16, '$a > 1', '$a >= 1')],
        'killed' => [
            resultsMutant($at, 'Plus', 'src/Money.php', 11, 'return $a + $b;', 'return $a - $b;'),
            resultsMutant($at, 'Plus', 'src/Held.php', 11, '$a + $a', '$a - $a'),
        ],
        'killedByStaticAnalysis' => [resultsMutant($at, 'TrueValue', 'src/Money.php', 30, 'true', 'false')],
        'errored' => [resultsMutant($at, 'Throw_', 'src/Money.php', 31, 'throw $e;', '$e;')],
        'syntaxErrors' => [resultsMutant($at, 'Concat', 'src/Money.php', 32, '$a . $b', '$b . $a')],
        'timeouted' => [resultsMutant($at, 'Decrement', 'src/Money.php', 27, '$a--;', '$a++;')],
        'uncovered' => [resultsMutant($at, 'Minus', 'src/Money.php', 21, '$a - 1', '$a + 1')],
    ], text: ['Timed Out' => [[sprintf('%s/src/Money.php', $at->root()), 27, 'Decrement', 'n27', '$a--;', '$a++;']]]);
    $statuses = $result instanceof MutationResult ? array_map(
        static fn(Mutant $mutant): array => [
            $mutant->location()->file()->value(),
            $mutant->location()->start()->number(),
            $mutant->status(),
            $mutant->mutation()->family(),
            $mutant->nativeId(),
        ],
        iterator_to_array($result->mutants(), preserve_keys: false),
    ) : [];

    expect($statuses)->toBe([
        ['src/Held.php', 11, MutantStatus::Killed, MutatorFamily::Arithmetic, ''],
        ['src/Money.php', 11, MutantStatus::Killed, MutatorFamily::Arithmetic, ''],
        ['src/Money.php', 16, MutantStatus::Survived, MutatorFamily::Boundary, ''],
        ['src/Money.php', 21, MutantStatus::Uncovered, MutatorFamily::Arithmetic, ''],
        ['src/Money.php', 27, MutantStatus::TimedOut, MutatorFamily::Arithmetic, 'n27'],
        ['src/Money.php', 30, MutantStatus::KilledByStaticAnalysis, MutatorFamily::Literal, ''],
        ['src/Money.php', 31, MutantStatus::Errored, MutatorFamily::Exception, ''],
        ['src/Money.php', 32, MutantStatus::Errored, MutatorFamily::None, ''],
    ])->and($result instanceof MutationResult ? $result->skipped() : -1)->toBe(0)
        ->and($result instanceof MutationResult ? iterator_to_array($result->mutants(), preserve_keys: false)[1] : null)
        ->toEqual(Mutant::of(
            MutantId::hash(Path::of('src/Money.php'), 'Plus', $diff, 0),
            '',
            Location::of(Path::of('src/Money.php'), Line::of(11), Unreported::line()),
            Mutation::of('Plus', MutatorFamily::Arithmetic, $diff),
            MutantStatus::Killed,
            Unmeasured::duration(),
        ))
        ->and($result instanceof MutationResult ? iterator_to_array($result->mutants(), preserve_keys: false)[4]->limit() : null)
        ->toEqual(Seconds::of(5.0));
});

it('names the tests that killed a mutant, and none for one static analysis, an error or a timeout killed', function (): void {
    $at = resultsProject();
    $failed = "There was 1 failure:\n\n1) Tests\\MoneySpec::addsTwoAmounts#0 with data (2, 3)\nFailed.";
    $result = resultsRead($at, [
        'killed' => [
            [...resultsMutant($at, 'Plus', 'src/Money.php', 11, 'return $a + $b;', 'return $a - $b;'), 'processOutput' => $failed],
            array_diff_key(resultsMutant($at, 'Plus', 'src/Held.php', 11, '$a + $a', '$a - $a'), ['processOutput' => true]),
        ],
        'killedByStaticAnalysis' => [[...resultsMutant($at, 'TrueValue', 'src/Money.php', 30, 'true', 'false'), 'processOutput' => $failed]],
        'errored' => [[...resultsMutant($at, 'Throw_', 'src/Money.php', 31, 'throw $e;', '$e;'), 'processOutput' => $failed]],
        'timeouted' => [[...resultsMutant($at, 'Decrement', 'src/Money.php', 27, '$a--;', '$a++;'), 'processOutput' => $failed]],
    ], text: ['Timed Out' => [[sprintf('%s/src/Money.php', $at->root()), 27, 'Decrement', 'n27', '$a--;', '$a++;']]]);
    $killers = $result instanceof MutationResult ? array_map(
        static fn(Mutant $mutant): array => [
            sprintf('%s:%d', $mutant->location()->file()->value(), $mutant->location()->start()->number()),
            array_map(static fn(TestId $test): string => $test->value(), [...$mutant->killers()]),
        ],
        iterator_to_array($result->mutants(), preserve_keys: false),
    ) : [];

    expect($killers)->toBe([
        ['src/Held.php:11', []],
        ['src/Money.php:11', ['Tests\\MoneySpec::addsTwoAmounts#0']],
        ['src/Money.php:27', []],
        ['src/Money.php:30', []],
        ['src/Money.php:31', []],
    ]);
});

it('counts mutants that share a file, a mutator and a change, so each has an id of its own', function (): void {
    $at = resultsProject();
    $diff = InfectionRun::diff('$a + 1', '$a - 1');
    $result = resultsRead($at, ['killed' => [
        resultsMutant($at, 'Plus', 'src/Money.php', 40, '$a + 1', '$a - 1'),
        resultsMutant($at, 'Plus', 'src/Money.php', 12, '$a + 1', '$a - 1'),
        resultsMutant($at, 'Plus', 'src/Held.php', 12, '$a + 1', '$a - 1'),
    ]]);
    $ids = $result instanceof MutationResult
        ? array_map(static fn(Mutant $mutant): string => $mutant->id()->value(), iterator_to_array($result->mutants(), preserve_keys: false))
        : [];

    expect($ids)->toBe([
        MutantId::hash(Path::of('src/Held.php'), 'Plus', $diff, 0)->value(),
        MutantId::hash(Path::of('src/Money.php'), 'Plus', $diff, 0)->value(),
        MutantId::hash(Path::of('src/Money.php'), 'Plus', $diff, 1)->value(),
    ]);
});

it('reads the skipped mutants from the text log, allowed the cap', function (): void {
    $at = resultsProject();
    $file = sprintf('%s/src/Money.php', $at->root());
    $result = resultsRead($at, [], counts: ['skippedCount' => 1], text: [
        'Skipped' => [[$file, 11, 'Plus', 's11', 'return $a + $b;', 'return $a - $b;']],
    ]);
    $mutants = $result instanceof MutationResult ? iterator_to_array($result->mutants(), preserve_keys: false) : [];

    expect(count($mutants))->toBe(1)
        ->and($mutants[0]->status())->toBe(MutantStatus::Skipped)
        ->and($mutants[0]->nativeId())->toBe('s11')
        ->and($mutants[0]->location()->file())->toEqual(Path::of('src/Money.php'))
        ->and($mutants[0]->limit())->toEqual(Seconds::of(7.5));
});

it('records a mutant a pattern of Infection\'s config ignored as ignored by a native marker, where markers are allowed', function (): void {
    $at = resultsProject();
    $result = resultsRead($at, ['ignored' => [resultsMutant($at, 'Plus', 'src/Money.php', 11, '$a + 1', '$a - 1')]], allowed: true);
    $mutants = $result instanceof MutationResult ? $result->mutants() : Mutants::none();

    expect(array_map(static fn(Mutant $mutant): MutantStatus => $mutant->status(), iterator_to_array($mutants, preserve_keys: false)))
        ->toBe([MutantStatus::IgnoredByMarker]);
});

it('cannot judge a run whose config ignored mutants by a pattern, where markers are refused', function (): void {
    $at = resultsProject();

    expect(resultsRead($at, ['ignored' => [resultsMutant($at, 'Plus', 'src/Money.php', 11, '$a + 1', '$a - 1')]]))
        ->toEqual(CannotJudge::because(
            "Infection's logs do not add up, so the gate cannot judge this run. "
            . 'Infection ignored 1 mutants by an ignoreSourceCodeByRegex pattern, which ignores.native refuses.',
        ));
});

it('cannot judge logs whose counts do not match what they list', function (): void {
    $at = resultsProject();
    $killed = [resultsMutant($at, 'Plus', 'src/Money.php', 11, '$a + 1', '$a - 1')];

    expect(resultsRead($at, ['killed' => $killed], counts: ['killedCount' => 2, 'escapedCount' => 1, 'totalMutantsCount' => 3]))
        ->toEqual(CannotJudge::because(
            "Infection's logs do not add up, so the gate cannot judge this run. "
            . "Infection's log counts 2 under killedCount but lists 1. Infection's log counts 1 under escapedCount but lists 0.",
        ))
        ->and(resultsRead($at, ['killed' => $killed], counts: ['skippedCount' => 2]))
        ->toEqual(CannotJudge::because(
            "Infection's logs do not add up, so the gate cannot judge this run. "
            . 'Infection counts 2 skipped mutants but its text log names 0.',
        ))
        ->and(resultsRead($at, ['killed' => $killed], counts: ['totalMutantsCount' => 4]))
        ->toEqual(CannotJudge::because(
            "Infection's logs do not add up, so the gate cannot judge this run. "
            . 'Infection counts 4 mutants in all, but its counts add up to 1.',
        ));
});

it('cannot judge a run that wrote no log, with what Infection said', function (): void {
    $at = resultsProject();

    expect(Results::read($at, Ran::finished(succeeded: false, output: 'Fatal'), TextLog::read(''), resultsLimits($at), nativeMarkersAllowed: false))
        ->toEqual(CannotJudge::because("Infection wrote no log, so no mutant it ran has a result. Infection said:\nFatal"));
});

it('cannot judge a log that is not in the shape Infection writes', function (): void {
    $at = resultsProject();
    Scratch::write($at->root(), '.gate/infection/logs/infection.json', '{"stats": {"killedCount": "one"}}');

    expect(Results::read($at, Ran::finished(succeeded: true, output: ''), TextLog::read(''), resultsLimits($at), nativeMarkersAllowed: false))
        ->toEqual(CannotJudge::because("Infection's log is not in the shape the gate reads: the file.stats.killedCount is not a whole number."));
});
