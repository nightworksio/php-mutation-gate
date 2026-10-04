<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\Order\Seed;
use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordEvent;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\TestOrder;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Order\Enclosing;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Order\Kills;
use NightWorksIO\MutationGate\Core\Order\Ordering;
use NightWorksIO\MutationGate\Core\Order\Ranking;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Tests\Contract\Runner\Library;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use Symfony\Component\Process\Process;

// The likely killers first (ADR-0013, decision 3), under Pest's real runs. In
// the fixture's MoneySpec a second test kills a change to Money::add after the
// first, so where the order runs it first, it is the one named as the killer.
// Ordering changes when a killer is found, never whether: a survivor stays one
// whatever a mutant's own process was started with.

/** The fixture's second killer of a change to Money::add, as the coverage map names it. */
const ORDER_TWICE = 'P\Tests\MoneySpec::__pest_evaluable_it_adds_the_same_amount_to_itself';

/** The fixture's first. */
const ORDER_FIRST = 'P\Tests\MoneySpec::__pest_evaluable_it_adds_two_amounts';

/**
 * Each of Money's mutants, by the line it is on: its status and the tests
 * named as its killers.
 *
 * @return array<int, array{string, list<string>}>
 */
function orderJudged(MutationResult|CannotJudge $result): array
{
    $judged = [];

    foreach ($result instanceof MutationResult ? $result->mutants() : [] as $mutant) {
        $judged[$mutant->location()->start()->number()] = [
            $mutant->status()->value,
            array_map(static fn(TestId $test): string => $test->value(), [...$mutant->killers()]),
        ];
    }

    return $judged;
}

/** Money's killed and surviving mutant, their tests run in an order. */
function orderRun(Ordering $ordering): MutationResult|CannotJudge
{
    $library = Library::pest(Patching::off());
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
        ->narrowedTo(Paths::of(Path::of('src/Money.php')), $library->mutators('adds', 'large'))
        ->orderedBy($ordering);

    return $library->runner()->mutate($request);
}

/** The gate's id of the fixture's killed mutant of Money::add. */
function orderAdds(): MutantId
{
    $id = MutantId::parse(Library::pest(Patching::off())->expected('adds')[0][0]);

    return $id instanceof MutantId ? $id : MutantId::hash(Path::of('src/Money.php'), '', '', 0);
}

it('runs a mutant\'s likely killer first, by its own history or its function\'s, so that test kills it', function (KillHistory $history): void {
    expect(orderJudged(orderRun(Ordering::of(TestOrder::KillersFirst, $history))))->toBe([
        11 => ['killed', [ORDER_TWICE]],
        16 => ['survived', []],
    ]);
})->with([
    'its own' => fn(): KillHistory => KillHistory::none()->withMutant(orderAdds(), Ranking::of(Kills::of(TestId::of(ORDER_TWICE), 3))),
    'its function\'s' => fn(): KillHistory => KillHistory::none()->withFunction(
        Enclosing::named(Path::of('src/Money.php'), 'add'),
        Ranking::of(Kills::of(TestId::of(ORDER_TWICE), 1)),
    ),
])->skip(! Library::isInstalled(), 'the runner contracts job installs the fixture library');

it('judges every mutant as the runner\'s own order does, with no history at all', function (): void {
    $cold = orderJudged(orderRun(Ordering::of(TestOrder::KillersFirst, KillHistory::none())));
    $own = orderJudged(orderRun(Ordering::runner()));

    expect(array_map(static fn(array $judged): string => $judged[0], $cold))->toBe([11 => 'killed', 16 => 'survived'])
        ->and($own)->toBe([11 => ['killed', [ORDER_FIRST]], 16 => ['survived', []]]);
})->skip(! Library::isInstalled(), 'the runner contracts job installs the fixture library');

/**
 * Runs the fixture's MoneySpec as a mutant's own process does, on a mutated
 * copy of src/Money.php, with an order that runs the second killer of
 * Money::add first, and these arguments before Pest's own. Answers its exit
 * code and the tests the plugin named as killers.
 *
 * @return array{int, list<string>}
 */
function orderChild(string $from, string $to, string ...$arguments): array
{
    $root = Tree::at(Library::DIRECTORY);
    $scratch = Scratch::directory();
    $results = sprintf('%s/results.jsonl', $scratch);
    $order = sprintf('%s/order', $scratch);
    $mutated = sprintf('%s/mutated', $scratch);
    file_put_contents($mutated, str_replace($from, $to, (string) file_get_contents(sprintf('%s/src/Money.php', $root))));
    $seed = Seed::directoryOf($order, $mutated);
    mkdir($seed, recursive: true);
    $version = new Process([PHP_BINARY, '-r', 'require "vendor/autoload.php"; echo Pest\version();'], $root);
    $version->run();
    file_put_contents(sprintf('%s/%s', $seed, Seed::HISTORY), (string) json_encode([
        'version' => sprintf('pest_%s', $version->getOutput()),
        'defects' => ['P\Tests\MoneySpec::__pest_evaluable_it_adds_the_same_amount_to_itself' => 8],
        'times' => new stdClass(),
    ]));

    $child = new Process(
        [PHP_BINARY, 'vendor/bin/pest', '--no-tia', '--colors=never', ...$arguments, '--bail', '--filter=MoneySpec'],
        $root,
        [
            Recorder::MUTANT => sprintf('%s/src/Money.php', $root),
            Recorder::MUTATED => $mutated,
            GateVariable::Results->value => $results,
            GateVariable::Order->value => $order,
            'PARATEST' => false,
            'TEST_TOKEN' => false,
            'UNIQUE_TEST_TOKEN' => false,
        ],
    );
    $exit = $child->run();

    return [$exit, orderKilled($results)];
}

/**
 * The tests the plugin named as killers in a results file, in order: its
 * other lines, such as how many tests the run ran, name none.
 *
 * @return list<string>
 */
function orderKilled(string $results): array
{
    $lines = is_file($results) ? explode("\n", trim((string) file_get_contents($results))) : [];
    $records = array_map(
        static fn(string $line): Node => Node::decode($line),
        array_values(array_filter($lines, static fn(string $line): bool => $line !== '')),
    );
    $killers = array_filter($records, static fn(Node $record): bool => in_array(
        Lenient::text($record->field('event')),
        [RecordEvent::Killed->value, RecordEvent::Errored->value],
        strict: true,
    ));

    return array_values(array_map(static fn(Node $record): string => Lenient::text($record->field('test')), $killers));
}

it('keeps a survivor a survivor and a kill a kill, first by the likely killer, whatever a mutant\'s process starts with', function (string ...$arguments): void {
    expect(orderChild('return $amount > 100;', 'return $amount >= 100;', ...$arguments))->toBe([0, []])
        ->and(orderChild('return $a + $b;', 'return $a - $b;', ...$arguments))->toBe([1, [ORDER_TWICE]]);
})->with([
    'nothing more' => [],
    'a cache directory' => ['--cache-directory=.mutation-gate/elsewhere'],
    'a cache directory, its value apart' => ['--cache-directory', '.mutation-gate/elsewhere'],
    'no cached results' => ['--do-not-cache-result'],
    'no recorded history' => ['--do-not-record-test-run-history'],
    'a random order' => ['--order-by=random'],
])->skip(! Library::isInstalled(), 'the runner contracts job installs the fixture library');
