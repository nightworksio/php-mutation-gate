<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Proof\Inputs;
use NightWorksIO\MutationGate\Core\Proof\InputsTable;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Undigested;
use NightWorksIO\MutationGate\Tests\Support\Moment;

$proof = static fn(string $unit, Inputs|Undigested $inputs): Proof => Proof::of(
    Digest::sha256Of($unit),
    Path::of($unit),
    Mutants::none(),
    Run::of('run', Moment::at('2026-09-29T10:00:00Z'), Digest::sha256Of('base')),
)->withInputs($inputs);
$commit = Revision::ref(str_repeat('c0', 20));
$money = Inputs::of(Digest::sha256Of('money'), Digest::sha256Of('mutation'))
    ->withTest(Path::of('tests/MoneyTest.php'), Digest::sha256Of('money test'))
    ->takenAt($commit);
$tax = Inputs::of(Digest::sha256Of('tax'), Digest::sha256Of('mutation'))
    ->withTest(Path::of('tests/TaxTest.php'), Digest::sha256Of('tax test'))
    ->withTest(Path::of('tests/MoneyTest.php'), Digest::sha256Of('money test'));
$table = InputsTable::of($proof('src/Money.php', $money), $proof('src/Held.php', Undigested::proof()), $proof('src/Tax.php', $tax));

it('writes what the proofs\' digests share once each, in the order they first record it', function () use ($table): void {
    expect($table->written())->toBe([
        'mutation' => [Digest::sha256Of('mutation')->value()],
        'tests' => [
            ['tests/MoneyTest.php', Digest::sha256Of('money test')->value()],
            ['tests/TaxTest.php', Digest::sha256Of('tax test')->value()],
        ],
        'commits' => [str_repeat('c0', 20)],
    ]);
});

it('gives each shared digest its index, and reads each index back as the digest', function () use ($table, $commit): void {
    $read = InputsTable::read(Node::config(JsonText::encode($table->written())));

    expect($table->mutation(Digest::sha256Of('mutation')))->toBe(0)
        ->and($table->test(Path::of('tests/TaxTest.php'), Digest::sha256Of('tax test')))->toBe(1)
        ->and($table->commit($commit))->toBe(0)
        ->and($read->mutationAt(Node::config('0')))->toEqual(Digest::sha256Of('mutation'))
        ->and($read->testAt(Node::config('1')))->toEqual([Path::of('tests/TaxTest.php'), Digest::sha256Of('tax test')])
        ->and($read->commitAt(Node::config('0')))->toEqual($commit);
});

it('refuses an index past the end of its list', function (Closure $at) use ($table): void {
    $read = InputsTable::read(Node::config(JsonText::encode($table->written())));

    expect(fn(): mixed => $at($read))->toThrow(NotInShape::class);
})->with([
    'a mutation digest' => [static fn(InputsTable $read): Digest => $read->mutationAt(Node::config('1'))],
    'a test file' => [static fn(InputsTable $read): array => $read->testAt(Node::config('2'))],
    'a commit' => [static fn(InputsTable $read): Revision => $read->commitAt(Node::config('-1'))],
    'an index that is not a number' => [static fn(InputsTable $read): Revision => $read->commitAt(Node::config('"0"'))],
]);

it('reads no list with an entry that is not well formed, and every other as it is', function (string $list, array $entries, Closure $at) use ($table): void {
    $read = InputsTable::read(Node::config(JsonText::encode([...$table->written(), $list => $entries])));

    expect(fn(): mixed => $at($read))->toThrow(NotInShape::class)
        ->and($read->written())->toBe([...$table->written(), $list => []]);
})->with([
    'a mutation digest that is not a SHA-256' => ['mutation', ['abc'], static fn(InputsTable $read): Digest => $read->mutationAt(Node::config('0'))],
    'a test file with no digest' => ['tests', [['tests/MoneyTest.php']], static fn(InputsTable $read): array => $read->testAt(Node::config('0'))],
    'a test file whose digest is not text' => ['tests', [['tests/MoneyTest.php', 7]], static fn(InputsTable $read): array => $read->testAt(Node::config('0'))],
    'a test file with more than its digest' => ['tests', [['tests/MoneyTest.php', str_repeat('3', 64), 'more']], static fn(InputsTable $read): array => $read->testAt(Node::config('0'))],
    'a commit that is not a full commit id' => ['commits', ['HEAD'], static fn(InputsTable $read): Revision => $read->commitAt(Node::config('0'))],
]);

it('holds nothing of proofs that record no digests, nor of a section there is not', function () use ($proof): void {
    expect(InputsTable::of($proof('src/Held.php', Undigested::proof()))->written())->toBe(['mutation' => [], 'tests' => [], 'commits' => []])
        ->and(InputsTable::read(Node::config('{}'))->written())->toBe(['mutation' => [], 'tests' => [], 'commits' => []]);
});
