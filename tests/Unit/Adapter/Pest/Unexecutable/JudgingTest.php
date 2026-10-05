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
use NightWorksIO\MutationGate\Core\Config\Triage;
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
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Tests\Support\CoverageMaps;
use NightWorksIO\MutationGate\Tests\Support\MatchHeads;
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

it('judges each uncovered mutant on a line that is not executable by the tests that read its value, giving none that did not time out a limit', function () use (
    $money,
): void {
    $at = Unexecutables::project();
    $results = Unexecutables::run($at, ['rate', 'unread', 'internal', 'other']);
    $shell = new ShellFake(static fn(Command $command): Ran => Unexecutables::answering($command, ['tests/OtherSpec.php', 'tests/MoneySpec.php']));
    $judged = new Judging($at, $shell, new CapDirectory(), Triage::standard()->limit())->of(judgingResult('rate', 'unread', 'internal', 'other'), $money, $results, judgingCoverage($results));

    expect(judgingOutcomes($judged))->toBe([
        'rate' => 'killed',
        'unread' => 'unjudged no test reaches this value',
        'internal' => 'killed',
        'other' => 'uncovered',
    ])->and($judged instanceof MutationResult ? $judged->skipped() : -1)->toBe(2)
        ->and($judged instanceof MutationResult ? array_map(static fn(Mutant $mutant): mixed => $mutant->limit(), [...$judged->mutants()]) : [])
        ->toEqual(array_fill(0, 4, Unmeasured::duration()));
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

    new Judging($at, $shell, new CapDirectory(), Triage::standard()->limit())->of(
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

    expect(new Judging($at, new ShellFake(static fn(): Ran => Ran::finished(succeeded: true, output: '')), new CapDirectory(), Triage::standard()->limit())->of(
        judgingResult('internal'),
        $money->cappedAt(MemoryCap::standard()),
        $results,
        judgingCoverage($results),
    ))->toBeInstanceOf(CannotJudge::class);
});

it('runs the tests that read the value, then the fallback\'s others where the mutant came through, each within its own tests\' limit', function () use (
    $money,
): void {
    $at = Unexecutables::project();
    $results = Unexecutables::run($at, ['internal']);
    $shell = new ShellFake(static fn(Command $command): Ran => Unexecutables::answering($command, ['tests/OtherSpec.php']));
    $log = sprintf('--log-junit=%s/.mutation-gate/pest/trials/0/junit.xml', $at->root());
    $alone = static fn(string $spec, float $limit): Command => Invocation::installedIn(Path::of('vendor'))
        ->judging(Paths::of(Path::of(sprintf('tests/%s.php', $spec))), WholeSuite::tests(), Withheld::standard(), $log)
        ->within(Seconds::of($limit));
    $copy = Recorder::mutantBeside($results, 'internal');
    $override = static fn(string $spec, float $limit): Command => $alone($spec, $limit)->with([
        'PEST_MUTATION_TESTING' => sprintf('%s/src/Money.php', $at->root()),
        'PEST_MUTATION_FILE' => $copy,
        'MUTATION_GATE_GUARD' => sprintf('%s/.mutation-gate/pest/trials/0/guard.json', $at->root()),
    ]);

    new Judging($at, $shell, new CapDirectory(), Triage::standard()->limit())->of(judgingResult('internal'), $money, $results, judgingCoverage($results));

    expect($shell->commands())->toEqual([
        $alone('InternalSpec', 5.5),
        $override('InternalSpec', 5.5),
        $alone('OtherSpec', 6.5),
        $override('OtherSpec', 6.5),
    ]);
});

it('lets the tests that read an ambiguous value kill it whatever the fallback holds, and leaves unjudged what they leave alive past ten', function () use (
    $money,
): void {
    $at = Unexecutables::project();
    Scratch::write($at->root(), 'src/Dynamic.php', "<?php\nnamespace App;\nfinal class Dynamic { public function rate(string \$class): int { return \$class::RATE; } }\n");
    $results = Unexecutables::run($at, ['rate']);
    $specs = [];

    foreach (range(1, 11) as $each) {
        Scratch::write($at->root(), sprintf('tests/S%dSpec.php', $each), "<?php\nit('runs', fn () => new App\\Money()->internal());\n");
        $specs[] = sprintf('P\\Tests\\S%dSpec::__pest_evaluable_it_runs', $each);
    }

    $tests = [...$specs, 'P\\Tests\\MoneySpec::__pest_evaluable_it_reads'];
    CoverageMaps::write(
        Recorder::coverageBeside($results),
        sprintf('%s/', $at->root()),
        ['src/Money.php' => [10 => range(0, 10)], 'src/Dynamic.php' => [3 => [0]]],
        $tests,
        array_fill_keys($tests, 0.1),
    );
    $judged = static fn(string ...$killing): MutationResult|CannotJudge => new Judging(
        $at,
        new ShellFake(static fn(Command $command): Ran => Unexecutables::answering($command, array_values($killing))),
        new CapDirectory(),
        Triage::standard()->limit(),
    )->of(judgingResult('rate'), $money, $results, judgingCoverage($results));

    expect(judgingOutcomes($judged('tests/MoneySpec.php')))->toBe(['rate' => 'killed'])
        ->and(judgingOutcomes($judged()))
        ->toBe(['rate' => 'unjudged ambiguous reference; src/Money.php is covered by 11 test files']);
});

it('judges a mutant of a statement\'s first line by the tests that run its other lines, one in a match\'s head by those that run its arms, and leaves an arm and a statement of one line uncovered', function (): void {
    $at = MatchHeads::project();
    $results = MatchHeads::run($at);
    $band = MutationRequest::of(Paths::of(Path::of('src/Band.php')), WholeSuite::tests());
    $judged = static fn(string ...$killing): MutationResult|CannotJudge => new Judging(
        $at,
        new ShellFake(static fn(Command $command): Ran => Unexecutables::answering($command, array_values($killing))),
        new CapDirectory(),
        Triage::standard()->limit(),
    )->of(
        MutationResult::of(Mutants::of(...array_map(MatchHeads::mutant(...), ['head', 'arm', 'flat', 'inner'])), 0),
        $band,
        $results,
        judgingCoverage($results),
    );

    expect(judgingOutcomes($judged('tests/BandSpec.php')))
        ->toBe(['head' => 'killed', 'arm' => 'uncovered', 'flat' => 'uncovered', 'inner' => 'killed'])
        ->and(judgingOutcomes($judged()))
        ->toBe(['head' => 'survived', 'arm' => 'uncovered', 'flat' => 'uncovered', 'inner' => 'survived']);
});

it('gives a mutant that timed out the limit its run was allowed: five seconds and five times its tests\' own time, under the cap', function () use ($money): void {
    $at = Unexecutables::project();
    $results = Unexecutables::run($at, ['rate']);
    $limits = static function (Seconds $cap) use ($at, $money, $results): array {
        $shell = new ShellFake(static fn(Command $command, int $before): Ran => $before === 0
            ? Ran::finished(succeeded: true, output: '')
            : Ran::stopped(''));
        $judged = new Judging($at, $shell, new CapDirectory(), $cap)->of(judgingResult('rate'), $money, $results, judgingCoverage($results));
        $mutants = $judged instanceof MutationResult ? iterator_to_array($judged->mutants(), preserve_keys: false) : [];

        return array_map(static fn(Mutant $mutant): array => [$mutant->status(), $mutant->limit()], $mutants);
    };

    expect($limits(Triage::standard()->limit()))->toEqual([[MutantStatus::TimedOut, Seconds::of(5.5)]])
        ->and($limits(Seconds::of(5.2)))->toEqual([[MutantStatus::TimedOut, Seconds::of(5.2)]]);
});

it('judges only the uncovered mutants of a run, and keeps each mutant Pest judged as Pest judged it', function () use ($money): void {
    $at = Unexecutables::project();
    $results = Unexecutables::run($at, ['rate', 'internal']);
    $shell = new ShellFake(static fn(Command $command): Ran => Unexecutables::answering($command, ['tests/OtherSpec.php', 'tests/MoneySpec.php']));
    $internal = Unexecutables::mutant('internal');
    $survived = Mutant::of(
        $internal->id(),
        $internal->nativeId(),
        $internal->location(),
        $internal->mutation(),
        MutantStatus::Survived,
        Unmeasured::duration(),
    );
    $run = MutationResult::of(Mutants::of($survived, Unexecutables::mutant('rate')), 0);

    expect(judgingOutcomes(new Judging($at, $shell, new CapDirectory(), Triage::standard()->limit())->of($run, $money, $results, judgingCoverage($results))))
        ->toBe(['internal' => 'survived', 'rate' => 'killed']);
});

it('judges nothing without the mutated copy, and leaves the rest of a run as it was', function () use ($money): void {
    $at = Unexecutables::project();
    $results = Unexecutables::run($at, ['rate'], uncopied: ['rate']);
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));
    $killed = judgingResult('rate');
    $settled = MutationResult::of(Mutants::none(), 0);

    expect(judgingOutcomes(new Judging($at, $shell, new CapDirectory(), Triage::standard()->limit())->of($killed, $money, $results, judgingCoverage($results))))
        ->toBe(['rate' => 'unjudged mutated file missing'])
        ->and(new Judging($at, $shell, new CapDirectory(), Triage::standard()->limit())->of($settled, $money, $results, judgingCoverage($results)))->toBe($settled)
        ->and(new Judging($at, $shell, new CapDirectory(), Triage::standard()->limit())->of($killed, MutationRequest::of(Paths::none(), Filter::matching('A')), $results, judgingCoverage($results)))
        ->toBe($killed)
        ->and($shell->commands())->toBe([]);
});

it('judges nothing of a mutant whose original file is gone', function () use ($money): void {
    $at = Unexecutables::project();
    $results = Unexecutables::run($at, ['rate']);
    unlink(sprintf('%s/src/Money.php', $at->root()));
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));

    expect(judgingOutcomes(new Judging($at, $shell, new CapDirectory(), Triage::standard()->limit())->of(judgingResult('rate'), $money, $results, judgingCoverage($results))))
        ->toBe(['rate' => 'unjudged mutated file missing'])
        ->and($shell->commands())->toBe([]);
});

it('leaves unjudged a mutant of a file that no longer parses, so cannot be printed as Pest prints it', function () use ($money): void {
    $at = Unexecutables::project();
    $results = Unexecutables::run($at, ['rate']);
    Scratch::write($at->root(), 'src/Money.php', "<?php\nfinal class Money {\n");
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));

    expect(judgingOutcomes(new Judging($at, $shell, new CapDirectory(), Triage::standard()->limit())->of(judgingResult('rate'), $money, $results, judgingCoverage($results))))
        ->toBe(['rate' => 'unjudged src/Money.php does not parse, so its mutant cannot be printed as Pest prints it: Syntax error, unexpected EOF on line 3'])
        ->and($shell->commands())->toBe([]);
});
