<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Order\Enclosing;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Order\Kills;
use NightWorksIO\MutationGate\Core\Order\Ranking;
use NightWorksIO\MutationGate\Core\Proof\KillersRecord;
use NightWorksIO\MutationGate\Core\Test\TestId;

$mutant = MutantId::hash(Path::of('src/Money.php'), 'Plus', "-a\n+b", 0);
$history = KillHistory::none()
    ->withMutant($mutant, Ranking::of(Kills::of(TestId::of('b'), 1), Kills::of(TestId::of('a'), 2)))
    ->withFunction(Enclosing::named(Path::of('src/Money.php'), 'add'), Ranking::of(Kills::of(TestId::of('c'), 4)));

it('names every test a history holds, once, in the order it first names it', function () use ($history): void {
    expect(KillersRecord::testsOf($history))->toBe(['a', 'b', 'c'])
        ->and(KillersRecord::testsOf(KillHistory::none()))->toBe([]);
});

it('writes each ranking as index and kills pairs, most kills first, and reads back what it wrote', function () use ($history, $mutant): void {
    $written = KillersRecord::of($history, ['a' => 0, 'b' => 1, 'c' => 2]);

    expect(Json::compact($written))->toBe(Json::compact([
        'mutants' => [$mutant->value() => [[0, 2], [1, 1]]],
        'functions' => ['src/Money.php' => ['add' => [[2, 4]]]],
    ]))
        ->and(KillersRecord::read(Node::decode(Json::compact($written)), ['a', 'b', 'c']))->toEqual($history)
        ->and(Json::compact(KillersRecord::of(KillHistory::none(), [])))->toBe('{"mutants":{},"functions":{}}')
        ->and(KillersRecord::read(Node::decode('{}'), []))->toEqual(KillHistory::none());
});
