<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Mutant\KilledRecords;
use NightWorksIO\MutationGate\Core\Proof\InputsTable;
use NightWorksIO\MutationGate\Core\Proof\ProofsRead;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Tests\Support\Moment;

it('builds each unit and each run once, for every proof of it', function (): void {
    $killed = KilledRecords::of([], []);
    $inputs = InputsTable::read(Node::decode('{}'));
    $read = ProofsRead::of($killed, $inputs);
    $base = Digest::of(str_repeat('b', 64));
    $at = Moment::at('2026-09-30T12:00:00Z');

    expect($read->unit('src/Money.php'))->toBe($read->unit('src/Money.php'))
        ->and($read->unit('src/Tax.php'))->not->toBe($read->unit('src/Money.php'))
        ->and($read->run('github:1/1', $at, $base))->toBe($read->run('github:1/1', Moment::at('2026-09-30T12:00:00Z'), $base))
        ->and($read->run('github:1/1', $at, $base))->toEqual(Run::of('github:1/1', $at, $base))
        ->and($read->run('github:1/2', $at, $base))->not->toBe($read->run('github:1/1', $at, $base))
        ->and($read->run('github:1/1', Moment::at('2026-09-30T13:00:00Z'), $base))->not->toBe($read->run('github:1/1', $at, $base))
        ->and($read->run('github:1/1', $at, Digest::of(str_repeat('c', 64))))->not->toBe($read->run('github:1/1', $at, $base))
        ->and([$read->killed(), $read->inputs()])->toBe([$killed, $inputs]);
});
