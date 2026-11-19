<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Adapter\Pest\PestSteps;
use NightWorksIO\MutationGate\Core\Config\ChildProcesses;
use NightWorksIO\MutationGate\Core\Config\Duplicates;
use NightWorksIO\MutationGate\Core\Config\LongFilter;
use NightWorksIO\MutationGate\Core\Config\NarrowedKillTrust;
use NightWorksIO\MutationGate\Core\Config\Preloaded;
use NightWorksIO\MutationGate\Core\Config\TestOrder;
use NightWorksIO\MutationGate\Core\Config\Toggle;
use NightWorksIO\MutationGate\Core\Config\Toggles;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Order\Kills;
use NightWorksIO\MutationGate\Core\Order\KillSearch;
use NightWorksIO\MutationGate\Core\Order\Ordering;
use NightWorksIO\MutationGate\Core\Order\Ranking;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Runner\Pool;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\Workers;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Tests\Contract\Runner\Library;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use Pest\Mutate\Mutators\Arithmetic\PlusToMinus;

$holds = [
    'holds:src/Adapter/Pest',
];

/** The test of the fixture the history names as the killer, second in Pest's own order. */
const FRESH_ORDER_KILLER = 'P\Tests\OrderedSpec::__pest_evaluable_it_adds';

/** The test first in Pest's own order, which kills too. */
const FRESH_ORDER_FIRST = 'P\Tests\OrderedSpec::__pest_evaluable_it_adds_first';

/** The test of the masked fixture that kills alone, and that its first test hides the mutant from after it. */
const FRESH_ORDER_MASKED = 'P\Tests\MaskedSpec::__pest_evaluable_it_doubles_two';

/**
 * Each mutant of the fixture's preloaded file of this name judged in a fresh run under `pest.freshOrder` and
 * `pest.freshRace`, in a pool of three places, by this history: its status and the tests named for its kill.
 *
 * @return list<array{string, list<string>}>
 */
function freshOrderJudged(bool $on, KillHistory $history, bool $racing = false, string $name = 'Ordered'): array
{
    $source = Paths::of(Path::of(sprintf('src/%s.php', $name)));

    $result = Library::pestPlacing(Patching::on(Library::canary())->stepping(PestSteps::of(
        Toggles::of(Toggle::PestFreshOrder, $on)->with(Toggle::PestFreshRace, $racing),
        NarrowedKillTrust::Vouch,
        LongFilter::Files,
        Duplicates::JudgeOnce,
        preloaded: Preloaded::Fresh,
        childProcesses: ChildProcesses::Serve,
    )))->runner()->mutate(
        MutationRequest::of($source, WholeSuite::tests())
            ->narrowedTo(
                $source,
                Narrowing::none()->toMutators(Mutators::named(PlusToMinus::class)),
            )
            ->searching(KillSearch::of(Ordering::of(TestOrder::KillersFirst, $history), MatrixKind::FirstKiller))
            ->across(Pool::of(ProcessCount::of(3), Workers::Fresh)),
    );

    return array_map(
        static fn(Mutant $mutant): array => [
            $mutant->status()->value,
            array_map(static fn(TestId $test): string => $test->value(), [...$mutant->killers()]),
        ],
        $result instanceof MutationResult ? [...$result->mutants()] : [],
    );
}

/** The mutants of the fixture's preloaded file, as a fresh run in Pest's own order finds them. */
function freshOrderMutants(): MutationResult|false
{
    $result = Library::pestPlacing(Patching::on(Library::canary()))->runner()->mutate(
        MutationRequest::of(Paths::of(Path::of('src/Ordered.php')), WholeSuite::tests())->narrowedTo(
            Paths::of(Path::of('src/Ordered.php')),
            Narrowing::none()->toMutators(Mutators::named(PlusToMinus::class)),
        ),
    );

    return $result instanceof MutationResult ? $result : false;
}

/**
 * Copies the fixture's preloaded file of this name and its spec into the library, judges its mutants twice so, and
 * removes them again.
 *
 * @param  Closure(): array{list<array{string, list<string>}>, list<array{string, list<string>}>} $run
 * @return array{list<array{string, list<string>}>, list<array{string, list<string>}>}
 */
function freshOrderPlaced(Closure $run, string $name = 'Ordered'): array
{
    $files = [];

    foreach (['src/%s.php', 'tests/%sSpec.php'] as $shape) {
        $placed = sprintf($shape, $name);
        $files[$placed] = sprintf('tests/Contract/Runner/fresh-order/%s', $placed);
    }

    foreach ($files as $placed => $shipped) {
        copy(Tree::at($shipped), Tree::at(sprintf('%s/%s', Library::DIRECTORY, $placed)));
    }

    Library::patched();

    try {
        return $run();
    } finally {
        foreach (array_keys($files) as $placed) {
            unlink(Tree::at(sprintf('%s/%s', Library::DIRECTORY, $placed)));
        }
    }
}

/** A history that names the fixture's second test as the killer of each mutant a fresh run finds. */
function freshOrderHistory(): KillHistory
{
    $found = freshOrderMutants();
    $history = KillHistory::none();

    foreach ($found instanceof MutationResult ? $found->mutants() : [] as $mutant) {
        $history = $history->withMutant($mutant->id(), Ranking::of(Kills::first(TestId::of(FRESH_ORDER_KILLER))));
    }

    return $history;
}

it('judges every mutant of a fresh run as Pest\'s own order does, its likely killer named first under pest.freshOrder', function (): void {
    [$off, $on] = freshOrderPlaced(static function (): array {
        $history = freshOrderHistory();

        return [freshOrderJudged(on: false, history: $history), freshOrderJudged(on: true, history: $history)];
    });

    expect(array_column($on, 0))->toBe(array_column($off, 0))
        ->and($off)->toBe([['killed', [FRESH_ORDER_FIRST]]])
        ->and($on)->toBe([['killed', [FRESH_ORDER_KILLER]]]);
})->skip(
    fn(): bool => ! Library::isInstalled(),
    'the runner contracts job installs the fixture library',
)->group(...$holds);

it('judges every mutant of a fresh run as the run alone does where pest.freshRace races its tests on idle places', function (): void {
    [$alone, $raced] = freshOrderPlaced(static fn(): array => [
        freshOrderJudged(on: false, history: KillHistory::none()),
        freshOrderJudged(on: false, history: KillHistory::none(), racing: true),
    ]);

    expect(array_column($raced, 0))->toBe(array_column($alone, 0))
        ->and(array_column($alone, 0))->toBe(['killed']);
})->skip(
    fn(): bool => ! Library::isInstalled(),
    'the runner contracts job installs the fixture library',
)->group(...$holds);

it('judges killed by a raced part a mutant its fresh run alone lets survive, where an earlier test hides it from the killing test, an approved difference', function (): void {
    [$alone, $raced] = freshOrderPlaced(static fn(): array => [
        freshOrderJudged(on: false, history: KillHistory::none(), name: 'Masked'),
        freshOrderJudged(on: false, history: KillHistory::none(), racing: true, name: 'Masked'),
    ], 'Masked');

    expect($alone)->toBe([['survived', []]])
        ->and($raced)->toBe([['killed', [FRESH_ORDER_MASKED]]]);
})->skip(
    fn(): bool => ! Library::isInstalled(),
    'the runner contracts job installs the fixture library',
)->group(...$holds);
