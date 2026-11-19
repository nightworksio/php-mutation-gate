<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Native\Placement;
use NightWorksIO\MutationGate\Adapter\Pest\FreshOrder;
use NightWorksIO\MutationGate\Adapter\Pest\Order\HistoryId;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Core\Config\TestOrder;
use NightWorksIO\MutationGate\Core\Coverage\TestDurations;
use NightWorksIO\MutationGate\Core\Coverage\TimedTest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Order\Kills;
use NightWorksIO\MutationGate\Core\Order\KillSearch;
use NightWorksIO\MutationGate\Core\Order\Ordering;
use NightWorksIO\MutationGate\Core\Order\Ranking;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A project holding `src/Money.php`, with Pest of this version installed, or none listed where it is ''. */
function freshOrderProject(string $pest): Project
{
    $root = (string) realpath(Scratch::directory());
    Scratch::write($root, 'src/Money.php', "<?php\nfunction add(){return 1+1;}\n");
    $packages = array_map(
        static fn(string $name): array => ['name' => $name, 'version' => $pest, 'source' => ['reference' => 'abc']],
        ['pestphp/pest', 'pestphp/pest-plugin-mutate', 'phpunit/phpunit', 'phpunit/php-code-coverage'],
    );

    if ($pest !== '') {
        Scratch::write($root, 'vendor/composer/installed.json', (string) json_encode(['packages' => $packages]));
    }

    return Project::at($root, Paths::none(), Path::of('.gate'), Path::of('vendor'));
}

function freshOrderMutant(): Mutant
{
    $diff = "--- Original\n+++ New\n@@ @@\n-1 + 1\n+1 - 1";

    return Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'PlusToMinus', $diff, 0),
        'PlusToMinus',
        Location::of(Path::of('src/Money.php'), Line::of(2), Line::of(2)),
        Mutation::of('PlusToMinus', MutatorFamily::Arithmetic, $diff),
        MutantStatus::Unjudged,
        Unmeasured::duration(),
    );
}

/**
 * The options a fresh run of the mutant asks for, with what it wrote for them, under this order and setting.
 *
 * @return array{list<string>, mixed}
 */
function freshOrderAsked(string $pest, TestOrder $order, bool $on): array
{
    $at = freshOrderProject($pest);
    $history = KillHistory::none()->withMutant(freshOrderMutant()->id(), Ranking::of(Kills::first(TestId::of('T::slow'))));
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
        ->searching(KillSearch::of(Ordering::of($order, $history), MatrixKind::FirstKiller));
    $times = TestDurations::none()->with(TimedTest::of('T::slow', 40.0), TimedTest::of('T::fast', 0.1));
    $placement = Placement::inPestRunner($at->root(), Paths::none(), Path::of('vendor'), Path::of('.gate'));
    $options = FreshOrder::of($at, $placement, $request, $times, $on)
        ->ofRun(freshOrderMutant(), TestIds::of(TestId::of('T::fast'), TestId::of('T::slow')), freshOrderMutant()->id()->value());
    $directory = $options === [] ? '' : substr($options[0], strlen('--cache-directory='));
    $written = $directory === '' ? '' : (string) file_get_contents(sprintf('%s/test-run-history', $directory));

    return [$options, $written === '' ? [] : json_decode($written, associative: true)];
}

it('asks a fresh run for its likely killers first and the rest fastest first, as its own run\'s seed says them', function (): void {
    [$options, $history] = freshOrderAsked('v5.3.4', TestOrder::KillersFirst, on: true);

    expect($options)->toHaveCount(3)
        ->and(array_slice($options, 1))->toBe(['--record-test-run-history', '--order-by=duration-ascending,defects'])
        ->and($options[0])->toStartWith('--cache-directory=')
        ->and($history)->toBe([
            'version' => 'pest_5.3.4',
            'defects' => [HistoryId::of('T::slow') => 8],
            'times' => [HistoryId::of('T::fast') => 0.1, HistoryId::of('T::slow') => 40.0],
        ]);
});

it('leaves a fresh run in Pest\'s own order under pest.freshOrder off, tests.order runner, or where Pest\'s release is unknown', function (string $pest, TestOrder $order, bool $on): void {
    expect(freshOrderAsked($pest, $order, $on))->toBe([[], []]);
})->with([
    'pest.freshOrder off' => ['v5.3.4', TestOrder::KillersFirst, false],
    'tests.order runner' => ['v5.3.4', TestOrder::Runner, true],
    'no installed Pest' => ['', TestOrder::KillersFirst, true],
    'a branch of Pest' => ['dev-main', TestOrder::KillersFirst, true],
]);
