<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\Invocation;
use NightWorksIO\MutationGate\Adapter\Pest\Ran;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Adapter\Pest\Unexecutable\Judging;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ShellFake;
use NightWorksIO\MutationGate\Tests\Support\Unexecutables;

afterEach(function (): void {
    Scratch::sweep();
});

/** Each mutant as status and reason, by its id. */
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

it('judges each uncovered mutant on a line that is not executable by the tests that read its value', function () use (
    $money,
): void {
    $at = Unexecutables::project();
    $results = Unexecutables::run($at, ['rate', 'unread', 'internal', 'other']);
    $shell = new ShellFake(static fn(Command $command): Ran => Unexecutables::answering($command, ['tests/OtherSpec.php', 'tests/MoneySpec.php']));
    $judged = new Judging($at, $shell)->of(judgingResult('rate', 'unread', 'internal', 'other'), $money, $results);

    expect(judgingOutcomes($judged))->toBe([
        'rate' => 'killed',
        'unread' => 'unjudged no test reaches this value',
        'internal' => 'killed',
        'other' => 'uncovered',
    ])->and($judged instanceof MutationResult ? $judged->skipped() : -1)->toBe(2);
});

it('runs the tests that read the value, then the fallback\'s others where the mutant came through', function () use (
    $money,
): void {
    $at = Unexecutables::project();
    $results = Unexecutables::run($at, ['internal']);
    $shell = new ShellFake(static fn(Command $command): Ran => Unexecutables::answering($command, ['tests/OtherSpec.php']));
    $judging = Invocation::installedIn(Path::of('vendor'));
    $copy = Recorder::mutantBeside($results, 'internal');
    $override = static fn(string $spec): Command => $judging
        ->judging(Paths::of(Path::of(sprintf('tests/%s.php', $spec))), WholeSuite::tests())
        ->within(Seconds::of(6.0))
        ->with([
            'PEST_MUTATION_TESTING' => sprintf('%s/src/Money.php', $at->root()),
            'PEST_MUTATION_FILE' => $copy,
            'MUTATION_GATE_GUARD' => sprintf('%s/.mutation-gate/pest/guard.json', $at->root()),
        ]);

    new Judging($at, $shell)->of(judgingResult('internal'), $money, $results);

    expect($shell->commands())->toEqual([
        $judging->judging(Paths::of(Path::of('tests/InternalSpec.php')), WholeSuite::tests())->within(Seconds::of(6.0)),
        $override('InternalSpec'),
        $judging->judging(Paths::of(Path::of('tests/OtherSpec.php')), WholeSuite::tests())->within(Seconds::of(6.0)),
        $override('OtherSpec'),
    ]);
});

it('gives a mutant that timed out the limit Pest allows each mutant', function () use ($money): void {
    $at = Unexecutables::project();
    $results = Unexecutables::run($at, ['rate']);
    $shell = new ShellFake(static fn(Command $command, int $before): Ran => $before === 0
        ? Ran::finished(succeeded: true, output: '')
        : Ran::stopped(''));
    $judged = new Judging($at, $shell)->of(judgingResult('rate'), $money, $results);
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

    expect(judgingOutcomes(new Judging($at, $shell)->of($killed, $money, $results)))
        ->toBe(['rate' => 'unjudged mutated file missing'])
        ->and(new Judging($at, $shell)->of($settled, $money, $results))->toBe($settled)
        ->and(new Judging($at, $shell)->of($killed, MutationRequest::of(Paths::none(), Filter::matching('A')), $results))
        ->toBe($killed)
        ->and($shell->commands())->toBe([]);
});

it('cannot judge where the run left no map or no records to read', function () use ($money): void {
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));
    $unmapped = Unexecutables::project();
    $mapless = Unexecutables::run($unmapped, ['rate']);
    unlink(Recorder::coverageBeside($mapless));
    $unrecorded = Unexecutables::project();
    $recordless = Unexecutables::run($unrecorded, ['rate']);
    unlink($recordless);

    expect(new Judging($unmapped, $shell)->of(judgingResult('rate'), $money, $mapless))->toBeInstanceOf(CannotJudge::class)
        ->and(new Judging($unrecorded, $shell)->of(judgingResult('rate'), $money, $recordless))
        ->toBeInstanceOf(CannotJudge::class)
        ->and($shell->commands())->toBe([]);
});
