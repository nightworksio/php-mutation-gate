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
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Tests\Contract\Runner\Library;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use Pest\Mutate\Mutators\Arithmetic\PlusToMinus;

$holds = [
    'holds:src/Adapter/Pest',
];

/** The test of the fixture that kills at once, after the one that pauses first in Pest's own order. */
const FRESH_ORDER_FAST = 'P\Tests\OrderedSpec::__pest_evaluable_it_adds';

/** The test that pauses before it kills, first in Pest's own order. */
const FRESH_ORDER_SLOW = 'P\Tests\OrderedSpec::__pest_evaluable_it_adds_after_a_pause';

/**
 * Each mutant of the fixture's preloaded file judged in a fresh run under `pest.freshOrder`, the history naming
 * this test as each one's killer: its status and the tests named for its kill.
 *
 * @return list<array{string, list<string>}>
 */
function freshOrderJudged(bool $on, KillHistory $history): array
{
    $result = Library::pestPlacing(Patching::on(Library::canary())->stepping(PestSteps::of(
        Toggles::of(Toggle::PestFreshOrder, $on),
        NarrowedKillTrust::Vouch,
        LongFilter::Files,
        Duplicates::JudgeOnce,
        preloaded: Preloaded::Fresh,
        childProcesses: ChildProcesses::Serve,
    )))->runner()->mutate(
        MutationRequest::of(Paths::of(Path::of('src/Ordered.php')), WholeSuite::tests())
            ->narrowedTo(
                Paths::of(Path::of('src/Ordered.php')),
                Narrowing::none()->toMutators(Mutators::named(PlusToMinus::class)),
            )
            ->searching(KillSearch::of(Ordering::of(TestOrder::KillersFirst, $history), MatrixKind::FirstKiller)),
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

it('judges every mutant of a fresh run as Pest\'s own order does, its likely killer named first under pest.freshOrder', function (): void {
    $files = [
        'src/Ordered.php' => 'tests/Contract/Runner/fresh-order/src/Ordered.php',
        'tests/OrderedSpec.php' => 'tests/Contract/Runner/fresh-order/tests/OrderedSpec.php',
    ];

    foreach ($files as $placed => $shipped) {
        copy(Tree::at($shipped), Tree::at(sprintf('%s/%s', Library::DIRECTORY, $placed)));
    }

    Library::patched();

    try {
        $found = freshOrderMutants();
        $history = KillHistory::none();

        foreach ($found instanceof MutationResult ? $found->mutants() : [] as $mutant) {
            $history = $history->withMutant($mutant->id(), Ranking::of(Kills::first(TestId::of(FRESH_ORDER_FAST))));
        }

        $off = freshOrderJudged(on: false, history: $history);
        $on = freshOrderJudged(on: true, history: $history);
    } finally {
        foreach (array_keys($files) as $placed) {
            unlink(Tree::at(sprintf('%s/%s', Library::DIRECTORY, $placed)));
        }
    }

    expect(array_column($on, 0))->toBe(array_column($off, 0))
        ->and($off)->toBe([['killed', [FRESH_ORDER_SLOW]]])
        ->and($on)->toBe([['killed', [FRESH_ORDER_FAST]]]);
})->skip(
    fn(): bool => ! Library::isInstalled(),
    'the runner contracts job installs the fixture library',
)->group(...$holds);
