<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Order\Enclosing;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Order\Kills;
use NightWorksIO\MutationGate\Core\Order\RankedFunction;
use NightWorksIO\MutationGate\Core\Order\RankedMutant;
use NightWorksIO\MutationGate\Core\Order\Ranking;
use NightWorksIO\MutationGate\Core\Proof\KillersRecord;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Tests\Support\Growth;

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

    expect(JsonText::compact($written))->toBe(JsonText::compact([
        'mutants' => [$mutant->value() => [[0, 2], [1, 1]]],
        'functions' => ['src/Money.php' => ['add' => [[2, 4]]]],
    ]))
        ->and(KillersRecord::read(Node::decode(JsonText::compact($written)), ['a', 'b', 'c']))->toEqual($history)
        ->and(JsonText::compact(KillersRecord::of(KillHistory::none(), [])))->toBe('{"mutants":{},"functions":{}}')
        ->and(KillersRecord::read(Node::decode('{}'), []))->toEqual(KillHistory::none());
});

it('reads back a mutant, a file and a function whose names read as numbers', function (): void {
    $id = MutantId::parse('123456789012');
    $numeric = ($id instanceof MutantId ? KillHistory::none()->withMutant($id, Ranking::of(Kills::of(TestId::of('a'), 1))) : KillHistory::none())
        ->withFunction(Enclosing::named(Path::of('7'), '12'), Ranking::of(Kills::of(TestId::of('a'), 2)));

    expect(KillersRecord::read(Node::decode(JsonText::compact(KillersRecord::of($numeric, ['a' => 0]))), ['a']))
        ->toEqual($numeric)
        ->and(iterator_count($numeric->mutants()))->toBe(1);
});

it('names a test whose id reads as a number as text', function (): void {
    $history = KillHistory::none()->withFunction(
        Enclosing::named(Path::of('src/Money.php'), 'add'),
        Ranking::of(Kills::of(TestId::of('12'), 1)),
    );

    expect(KillersRecord::testsOf($history))->toBe(['12']);
});

it('reads a history back in time linear in its mutants and functions', function (): void {
    $read = static function (int $size): Closure {
        $ranking = Ranking::of(Kills::of(TestId::of('a'), 1));
        $history = KillHistory::none()
            ->withMutants(...array_map(
                static fn(int $at): RankedMutant => RankedMutant::of(MutantId::hash(Path::of('src/Money.php'), 'Plus', sprintf("-a\n+%d", $at), 0), $ranking),
                range(1, $size),
            ))
            ->withFunctions(...array_map(
                static fn(int $at): RankedFunction => RankedFunction::of(Enclosing::named(Path::of('src/Money.php'), sprintf('f%d', $at)), $ranking),
                range(1, $size),
            ));
        $section = Node::decode(JsonText::compact(KillersRecord::of($history, ['a' => 0])));

        return static fn(): KillHistory => KillersRecord::read($section, ['a']);
    };

    expect(iterator_count($read(10)()->mutants()))->toBe(10)
        ->and(iterator_count($read(10)()->functions()))->toBe(10)
        ->and(Growth::of(2500, $read))->toBeLessThan(Growth::LINEAR);
});
