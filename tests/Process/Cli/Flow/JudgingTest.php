<?php

declare(strict_types=1);
use NightWorksIO\MutationGate\Adapter\Opcache\Compiler;
use NightWorksIO\MutationGate\Adapter\Opcache\Prover;
use NightWorksIO\MutationGate\Config\Equivalence;
use NightWorksIO\MutationGate\Config\Ignore;
use NightWorksIO\MutationGate\Config\Setting;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Tests\Fakes\ReporterFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\JudgingRuns;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

$holds = [
    'holds:src/Cli/Flow/StaticEquivalence.php',
    'holds:src/Core/Verdict/RedundantIgnores.php',
];

afterEach(function (): void {
    Scratch::sweep();
});

$makeTree = static fn(): Closure => JudgingRuns::tree(...);
$makeReporting = static fn(): Closure => JudgingRuns::reporting(...);
$makeJudged = static fn(): Closure => JudgingRuns::judged(...);

it('proves a survivor equivalent where it compiles to its original program, and leaves the others survivors', function (
    Setting $equivalence,
    string $judgement,
) use ($makeTree, $makeReporting, $makeJudged): void {
    $tree = $makeTree();
    $reporting = $makeReporting();
    $judged = $makeJudged();

    $verdict = JudgingRuns::verdictOf($judged(
        Planned::twoShards(),
        Flows::adapters(Flows::project(), [], $tree(Floor::of(0)), JudgingRuns::moneyLaidOut()),
        JudgingRuns::settings($equivalence),
        $reporting(new ReporterFake()),
    ));

    expect(JudgingRuns::judgements($verdict))->toMatchArray(['GreaterThan-16' => $judgement, 'Plus-11' => 'survived'])
        ->and(JudgingRuns::texts($verdict->warnings()))->toBe([]);
})->with([
    'by default' => [fn(): Equivalence => Equivalence::provenStatically(), 'equivalent'],
    'not where equivalence.static is false' => [fn(): Equivalence => Equivalence::notProvenStatically(), 'survived'],
])->group(...$holds);

it('says no mutant was checked, and proves none, where opcache gives no opcodes', function () use ($makeTree, $makeReporting, $makeJudged): void {
    $tree = $makeTree();
    $reporting = $makeReporting();
    $judged = $makeJudged();

    $project = Flows::project();
    $silent = new Prover(new Compiler(PHP_BINARY, sprintf('%s/.mutation-gate/equivalence', $project), 30.0, 1, ['opcache.opt_debug_level=0']));
    $verdict = JudgingRuns::verdictOf($judged(
        Planned::twoShards(),
        Flows::adapters($project, [], $tree(Floor::of(0)), JudgingRuns::moneyLaidOut(), $silent),
        JudgingRuns::settings(),
        $reporting(new ReporterFake()),
    ));

    expect(JudgingRuns::judgements($verdict))->toMatchArray(['GreaterThan-16' => 'survived'])
        ->and(JudgingRuns::texts($verdict->warnings()))->toBe(['No mutant was checked for equivalence: opcache is not available.']);
})->group(...$holds);

it('keeps an ignore that leaves out only mutants proven equivalent, and says it can go', function (
    Ignore $ignore,
    array $judgements,
    array $said,
) use ($makeTree, $makeReporting, $makeJudged): void {
    $tree = $makeTree();
    $reporting = $makeReporting();
    $judged = $makeJudged();

    $verdict = JudgingRuns::verdictOf($judged(
        Planned::twoShards(),
        Flows::adapters(Flows::project(), [], $tree(Floor::of(0)), JudgingRuns::moneyLaidOut()),
        JudgingRuns::settings($ignore),
        $reporting(new ReporterFake()),
    ));

    expect(JudgingRuns::judgements($verdict))->toMatchArray($judgements)
        ->and(JudgingRuns::texts($verdict->warnings()))->toBe($said)
        ->and(JudgingRuns::texts($verdict->failures()))->toBe([]);
})->with([
    'one that leaves out only proven ones' => [
        fn(): Ignore => Ignore::mutator('GreaterThan', 'src/Money.php', 'The bound is never reached'),
        ['GreaterThan-16' => 'ignored', 'Plus-11' => 'survived'],
        ['The ignore of GreaterThan in src/Money.php leaves out only mutants proven equivalent: this ignore can go.'],
    ],
    'not one that leaves out a survivor no proof covers' => [
        fn(): Ignore => Ignore::mutator('Plus', 'src/**', 'Both sums are the same'),
        ['GreaterThan-16' => 'equivalent', 'Plus-11' => 'ignored'],
        [],
    ],
])->group(...$holds);
