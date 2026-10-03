<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\CoverageFile;
use NightWorksIO\MutationGate\Adapter\Pest\Covering;
use NightWorksIO\MutationGate\Adapter\Pest\Invocation;
use NightWorksIO\MutationGate\Adapter\Pest\MemoryScan;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Adapter\Pest\Unexecutable\Judging;
use NightWorksIO\MutationGate\Adapter\Runtime\CapDirectory;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ShellFake;
use NightWorksIO\MutationGate\Tests\Support\Unexecutables;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * Each mutant as status and reason, by its id.
 *
 * @return array<string, string>
 */
function judgingOutcomes(MutationResult|CannotJudge $result): array
{
    $outcomes = [];

    foreach ($result instanceof MutationResult ? $result->mutants() : [] as $mutant) {
        $reason = $mutant->reason();
        $outcomes[$mutant->nativeId()] = trim(sprintf(
            '%s %s',
            $mutant->status()->value,
            $reason instanceof Reason ? $reason->text() : '',
        ));
    }

    return $outcomes;
}

/** A run's result of these uncovered mutants of Money. */
function judgingResult(string ...$ids): MutationResult
{
    return MutationResult::of(Mutants::of(...array_map(Unexecutables::mutant(...), $ids)), 2);
}

$money = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests());

/** The opening map a run of the fixture left beside its results. */
function judgingCoverage(string $results): Covering
{
    $coverage = CoverageFile::at(Recorder::coverageBeside($results));

    return $coverage instanceof CoverageFile ? $coverage : throw new RuntimeException('the run left no map');
}

it('judges each uncovered mutant on a line that is not executable by the tests that read its value', function () use (
    $money,
): void {
    $at = Unexecutables::project();
    $results = Unexecutables::run($at, ['rate', 'unread', 'internal', 'other']);
    $shell = new ShellFake(static fn(Command $command): Ran => Unexecutables::answering($command, ['tests/OtherSpec.php', 'tests/MoneySpec.php']));
    $judged = new Judging($at, $shell, new CapDirectory())->of(judgingResult('rate', 'unread', 'internal', 'other'), $money, $results, judgingCoverage($results));

    expect(judgingOutcomes($judged))->toBe([
        'rate' => 'killed',
        'unread' => 'unjudged no test reaches this value',
        'internal' => 'killed',
        'other' => 'uncovered',
    ])->and($judged instanceof MutationResult ? $judged->skipped() : -1)->toBe(2);
});

it('runs every judging of a mutant by reference under the run\'s memory cap, and removes it once done', function () use (
    $money,
): void {
    $at = Unexecutables::project();
    $results = Unexecutables::run($at, ['internal']);
    $read = [];
    $shell = new ShellFake(static function (Command $command) use ($results, &$read): Ran {
        $read[] = (string) file_get_contents(sprintf('%s/%s', MemoryScan::directoryBeside($results), MemoryCap::FILE));

        return Unexecutables::answering($command, ['tests/OtherSpec.php']);
    });
    $scan = MemoryCap::scanning(getenv(MemoryCap::SCAN_DIR), MemoryScan::directoryBeside($results));

    new Judging($at, $shell, new CapDirectory())->of(
        judgingResult('internal'),
        $money->cappedAt(MemoryCap::of(256, MemoryUnit::Megabytes)),
        $results,
        judgingCoverage($results),
    );

    expect($shell->commands())->not->toBeEmpty()
        ->and(array_map(static fn(Command $command): mixed => $command->environment()[MemoryCap::SCAN_DIR] ?? null, $shell->commands()))
        ->each->toBe($scan)
        ->and(array_unique($read))->toBe(["memory_limit=256M\n"])
        ->and(is_dir(MemoryScan::directoryBeside($results)))->toBeFalse();
});

it('cannot judge a mutant by reference where the memory cap cannot be written', function () use ($money): void {
    $at = Unexecutables::project();
    $results = Unexecutables::run($at, ['internal']);
    mkdir(sprintf('%s/%s', MemoryScan::directoryBeside($results), MemoryCap::FILE), recursive: true);

    expect(new Judging($at, new ShellFake(static fn(): Ran => Ran::finished(succeeded: true, output: '')), new CapDirectory())->of(
        judgingResult('internal'),
        $money->cappedAt(MemoryCap::standard()),
        $results,
        judgingCoverage($results),
    ))->toBeInstanceOf(CannotJudge::class);
});

it('runs the tests that read the value, then the fallback\'s others where the mutant came through', function () use (
    $money,
): void {
    $at = Unexecutables::project();
    $results = Unexecutables::run($at, ['internal']);
    $shell = new ShellFake(static fn(Command $command): Ran => Unexecutables::answering($command, ['tests/OtherSpec.php']));
    $log = sprintf('--log-junit=%s/.mutation-gate/pest/junit.xml', $at->root());
    $alone = static fn(string $spec): Command => Invocation::installedIn(Path::of('vendor'))
        ->judging(Paths::of(Path::of(sprintf('tests/%s.php', $spec))), WholeSuite::tests(), Withheld::standard(), $log)
        ->within(Seconds::of(6.0));
    $copy = Recorder::mutantBeside($results, 'internal');
    $override = static fn(string $spec): Command => $alone($spec)->with([
        'PEST_MUTATION_TESTING' => sprintf('%s/src/Money.php', $at->root()),
        'PEST_MUTATION_FILE' => $copy,
        'MUTATION_GATE_GUARD' => sprintf('%s/.mutation-gate/pest/guard.json', $at->root()),
    ]);

    new Judging($at, $shell, new CapDirectory())->of(judgingResult('internal'), $money, $results, judgingCoverage($results));

    expect($shell->commands())->toEqual([
        $alone('InternalSpec'),
        $override('InternalSpec'),
        $alone('OtherSpec'),
        $override('OtherSpec'),
    ]);
});

it('gives a mutant that timed out the limit Pest allows each mutant', function () use ($money): void {
    $at = Unexecutables::project();
    $results = Unexecutables::run($at, ['rate']);
    $shell = new ShellFake(static fn(Command $command, int $before): Ran => $before === 0
        ? Ran::finished(succeeded: true, output: '')
        : Ran::stopped(''));
    $judged = new Judging($at, $shell, new CapDirectory())->of(judgingResult('rate'), $money, $results, judgingCoverage($results));
    $mutants = $judged instanceof MutationResult ? iterator_to_array($judged->mutants(), preserve_keys: false) : [];

    expect(array_map(static fn(Mutant $mutant): MutantStatus => $mutant->status(), $mutants))->toBe([MutantStatus::TimedOut])
        ->and(array_map(static fn(Mutant $mutant): mixed => $mutant->limit(), $mutants))->toEqual([Seconds::of(6.0)]);
});

it('judges nothing without the mutated copy, and leaves the rest of a run as it was', function () use ($money): void {
    $at = Unexecutables::project();
    $results = Unexecutables::run($at, ['rate'], uncopied: ['rate']);
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));
    $killed = judgingResult('rate');
    $settled = MutationResult::of(Mutants::none(), 0);

    expect(judgingOutcomes(new Judging($at, $shell, new CapDirectory())->of($killed, $money, $results, judgingCoverage($results))))
        ->toBe(['rate' => 'unjudged mutated file missing'])
        ->and(new Judging($at, $shell, new CapDirectory())->of($settled, $money, $results, judgingCoverage($results)))->toBe($settled)
        ->and(new Judging($at, $shell, new CapDirectory())->of($killed, MutationRequest::of(Paths::none(), Filter::matching('A')), $results, judgingCoverage($results)))
        ->toBe($killed)
        ->and($shell->commands())->toBe([]);
});

it('cannot judge where the run left no records to read', function () use ($money): void {
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));
    $unrecorded = Unexecutables::project();
    $recordless = Unexecutables::run($unrecorded, ['rate']);
    $coverage = judgingCoverage($recordless);
    unlink($recordless);

    expect(new Judging($unrecorded, $shell, new CapDirectory())->of(judgingResult('rate'), $money, $recordless, $coverage))
        ->toBeInstanceOf(CannotJudge::class)
        ->and($shell->commands())->toBe([]);
});
