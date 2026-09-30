<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Mutant\KilledRecords;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Unreported;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;

$records = static fn(): KilledRecords => KilledRecords::of(['Plus', 'Minus'], ['MoneyTest::adds', 'TaxTest::rounds']);

it('reads the mutator and the killers a killed record points at', function () use ($records): void {
    $read = $records();

    expect($read->mutator(Node::decode('1')))->toBe('Minus')
        ->and($read->killers(Node::decode('[1, 0]')))
        ->toEqual(TestIds::of(TestId::of('TaxTest::rounds'), TestId::of('MoneyTest::adds')));
});

it('builds each set of killers and each line of a unit once, for every record that names the same', function () use (
    $records,
): void {
    $read = $records();
    $money = Path::of('src/Money.php');

    expect($read->killers(Node::decode('[0, 1]')))->toBe($read->killers(Node::decode('[0, 1]')))
        ->and($read->killers(Node::decode('[1, 0]')))->not->toBe($read->killers(Node::decode('[0, 1]')))
        ->and($read->location($money, Line::of(3)))->toBe($read->location(Path::of('src/Money.php'), Line::of(3)))
        ->and($read->location($money, Line::of(3)))->toEqual(Location::of($money, Line::of(3), Unreported::line()))
        ->and($read->location($money, Line::of(4)))->not->toBe($read->location($money, Line::of(3)))
        ->and($read->location(Path::of('src/Tax.php'), Line::of(3)))->not->toBe($read->location($money, Line::of(3)));
});

it('refuses an index past the ledger\'s lists, where it points', function (Closure $read, string $said) use (
    $records,
): void {
    expect(static fn(): mixed => $read($records()))->toThrow(NotInShape::class, $said);
})->with([
    'a mutator' => [static fn(KilledRecords $read): string => $read->mutator(Node::decode('2')), 'a mutator'],
    'a killer' => [
        static fn(KilledRecords $read): TestIds => $read->killers(Node::decode('[0, 2]')),
        'the index of a listed test, not 2',
    ],
]);
