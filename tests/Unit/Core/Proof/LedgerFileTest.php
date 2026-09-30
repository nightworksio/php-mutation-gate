<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\Unreported;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\LedgerFile;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Proofs;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Timing;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\Stopwatch;

$keyA = str_repeat('a', 64);
$keyB = str_repeat('b', 64);
$killedId = MutantId::hash(Path::of('src/Money.php'), 'Plus', "-+\n+-", 0);
$survivedId = MutantId::hash(Path::of('src/Money.php'), 'LessThan', "-<\n+<=", 0);

// A killed mutant as a ledger reads it back: only its id, line, mutator and status.
$killed = Mutant::of(
    $killedId,
    '',
    Location::of(Path::of('src/Money.php'), Line::of(44), Unreported::line()),
    Mutation::of('Plus', MutatorFamily::None, ''),
    MutantStatus::Killed,
    Unmeasured::duration(),
);
$survived = Mutant::of(
    $survivedId,
    '9a0b7e',
    Location::of(Path::of('src/Money.php'), Line::of(42), Line::of(42)),
    Mutation::of('LessThan', MutatorFamily::Boundary, "-<\n+<="),
    MutantStatus::Survived,
    Seconds::of(0.4),
);
$at = static fn(string $instant): Instant => Moment::at($instant);

$ledger = Ledger::empty()
    ->withProof(Proof::of(Digest::of($keyA), Path::of('src/Money.php'), Mutants::of($killed, $survived), Run::of('github:1/1', $at('2026-09-29T20:48:17Z'))))
    ->withProof(Proof::of(Digest::of($keyB), Path::of('src/B.php'), Mutants::none(), Run::of('github:2/1', $at('2026-09-29T21:00:00Z'))))
    ->withTiming(Timing::of(Path::of('src/Money.php'), Seconds::of(12.4), 'infection', $at('2026-09-29T20:48:17Z')))
    ->withPassed(Revision::ref('206b4e0'));

// The ledger's file as data, to change one entry of and write back.
$data = static function () use ($ledger): array {
    $data = json_decode(LedgerFile::encode($ledger), associative: true);

    return is_array($data) ? $data : [];
};
$written = static fn(array $file): string => Json::encode($file);

it('writes the newest proofs first, a killed mutant briefly and a survivor in full', function () use ($ledger, $keyA, $keyB, $killedId, $survivedId): void {
    expect(LedgerFile::encode($ledger))->toBe(Json::encode([
        'format' => 1,
        'proofs' => [
            $keyB => ['unit' => 'src/B.php', 'at' => '2026-09-29T21:00:00Z', 'run' => 'github:2/1', 'mutants' => []],
            $keyA => [
                'unit' => 'src/Money.php',
                'at' => '2026-09-29T20:48:17Z',
                'run' => 'github:1/1',
                'mutants' => [
                    ['id' => $killedId->value(), 'line' => 44, 'mutator' => 'Plus', 'status' => 'killed'],
                    [
                        'id' => $survivedId->value(),
                        'native' => '9a0b7e',
                        'file' => 'src/Money.php',
                        'line' => 42,
                        'end' => 42,
                        'mutator' => 'LessThan',
                        'family' => 'boundary',
                        'diff' => "-<\n+<=",
                        'status' => 'survived',
                        'seconds' => 0.4,
                    ],
                ],
            ],
        ],
        'timings' => ['src/Money.php' => ['seconds' => 12.4, 'runner' => 'infection', 'at' => '2026-09-29T20:48:17Z']],
        'passed' => '206b4e0',
    ]));
});

it('writes an empty ledger as empty maps and no passing commit', function (): void {
    expect(LedgerFile::encode(Ledger::empty()))->toBe("{\n    \"format\": 1,\n    \"proofs\": {},\n    \"timings\": {}\n}");
});

it('reads back the ledger it wrote', function () use ($ledger): void {
    expect(LedgerFile::decode(LedgerFile::encode($ledger)))->toEqual($ledger);
});

it('keeps two proofs as new as each other in the order it held them', function () use ($at): void {
    $proof = static fn(string $key): Proof => Proof::of(Digest::of($key), Path::of('src/A.php'), Mutants::none(), Run::of('local', $at('2026-09-29T20:00:00Z')));
    $ledger = Ledger::empty()->withProof($proof(str_repeat('c', 64)))->withProof($proof(str_repeat('1', 64)));

    expect(array_keys(Node::decode(LedgerFile::encode($ledger))->field('proofs')->entries()))
        ->toBe([str_repeat('c', 64), str_repeat('1', 64)]);
});

it('keeps the newest twenty thousand proofs', function () use ($at): void {
    $proofs = [Proof::of(Digest::of(str_repeat('0', 64)), Path::of('src/Old.php'), Mutants::none(), Run::of('old', $at('2026-01-01T00:00:00Z')))];

    for ($made = 1; $made <= 20_000; $made++) {
        $proofs[] = Proof::of(Digest::of(hash('sha256', sprintf('%d', $made))), Path::of('src/New.php'), Mutants::none(), Run::of('new', $at('2026-09-29T20:00:00Z')));
    }

    $kept = LedgerFile::decode(LedgerFile::encode(array_reduce($proofs, static fn(Ledger $ledger, Proof $proof): Ledger => $ledger->withProof($proof), Ledger::empty())))->proofs();

    expect(LedgerFile::KEPT)->toBe(20_000)
        ->and($kept)->toHaveCount(20_000)
        ->and($kept->has(Digest::of(str_repeat('0', 64))))->toBeFalse()
        ->and($kept->has(Digest::of(hash('sha256', '20000'))))->toBeTrue();
});

it('reads a file of another format, or no ledger at all, as an empty ledger', function (string $json): void {
    expect(LedgerFile::decode($json))->toEqual(Ledger::empty());
})->with([
    'a newer format' => ['{"format": 2, "proofs": {}, "timings": {}, "passed": "206b4e0"}'],
    'a format written as text' => ['{"format": "1", "passed": "206b4e0"}'],
    'no format' => ['{"passed": "206b4e0"}'],
    'text that is not JSON' => ['{"format": 1, "passed": '],
    'nothing' => [''],
]);

it('drops a proof that is not well formed and keeps the rest', function (Closure $spoil) use ($data, $written, $ledger, $keyA, $keyB): void {
    $file = $data();
    $file['proofs'] = $spoil($file['proofs'], $keyA);

    expect(LedgerFile::decode($written($file)))->toEqual($ledger->withoutProof(Digest::of($keyA)))
        ->and(LedgerFile::decode($written($file))->proofs()->has(Digest::of($keyB)))->toBeTrue();
})->with([
    'a key that is not a SHA-256' => [static fn(array $proofs, string $key): array => [...$proofs, strtoupper($key) => $proofs[$key], $key => null]],
    'a unit that is not text' => [static fn(array $proofs, string $key): array => array_replace_recursive($proofs, [$key => ['unit' => null]])],
    'an instant that is not one' => [static fn(array $proofs, string $key): array => array_replace_recursive($proofs, [$key => ['at' => 'yesterday']])],
    'no run' => [static fn(array $proofs, string $key): array => array_replace_recursive($proofs, [$key => ['run' => 5]])],
    'mutants that are not a list' => [static fn(array $proofs, string $key): array => array_replace_recursive($proofs, [$key => ['mutants' => 'none']])],
    'a survivor kept briefly' => [static fn(array $proofs, string $key): array => array_replace_recursive($proofs, [$key => ['mutants' => [0 => ['status' => 'survived']]]])],
    'a brief record with a status there is not' => [static fn(array $proofs, string $key): array => array_replace_recursive($proofs, [$key => ['mutants' => [0 => ['status' => 'nope']]]])],
    'a full record with a family there is not' => [static fn(array $proofs, string $key): array => array_replace_recursive($proofs, [$key => ['mutants' => [1 => ['family' => 'nope']]]])],
]);

it('drops a timing that is not well formed and keeps a timing of no time at all', function () use ($data, $written, $at): void {
    $file = $data();
    $file['timings'] = [
        'src/Negative.php' => ['seconds' => -0.5, 'runner' => 'pest', 'at' => '2026-09-29T20:00:00Z'],
        'src/Text.php' => ['seconds' => 'long', 'runner' => 'pest', 'at' => '2026-09-29T20:00:00Z'],
        'src/NoRunner.php' => ['seconds' => 1.0, 'at' => '2026-09-29T20:00:00Z'],
        'src/NoInstant.php' => ['seconds' => 1.0, 'runner' => 'pest', 'at' => 'yesterday'],
        'src/Nothing.php' => ['seconds' => 0.0, 'runner' => 'pest', 'at' => '2026-09-29T20:00:00Z'],
    ];

    expect(iterator_to_array(LedgerFile::decode($written($file))->timings(), preserve_keys: false))
        ->toEqual([Timing::of(Path::of('src/Nothing.php'), Seconds::of(0.0), 'pest', $at('2026-09-29T20:00:00Z'))]);
});

it('reads proofs and timings that are not maps as none, and keeps what else it holds', function () use ($data, $written): void {
    $file = [...$data(), 'proofs' => 7, 'timings' => 'none'];

    expect(LedgerFile::decode($written($file)))->toEqual(Ledger::empty()->withPassed(Revision::ref('206b4e0')));
});

it('reads a passing commit that is not text as none', function () use ($data, $written, $ledger): void {
    $file = [...$data(), 'passed' => 7];
    $read = LedgerFile::decode($written($file));

    expect($read->lastPassed())->toEqual(Ledger::empty()->lastPassed())
        ->and($read->proofs())->toEqual($ledger->proofs());
});

it('reads a full ledger and joins it to another in linear time', function () use ($at, $killed): void {
    $proofs = [];

    for ($made = 1; $made <= 20_000; $made++) {
        $proofs[] = Proof::of(Digest::of(hash('sha256', sprintf('%d', $made))), Path::of(sprintf('src/F%d.php', $made)), Mutants::of($killed), Run::of('new', $at('2026-09-29T20:00:00Z')));
    }

    $written = LedgerFile::encode(Ledger::empty()->withProofs(Proofs::of(...$proofs)));
    $read = Ledger::empty();

    $seconds = Stopwatch::seconds(static function () use ($written, &$read): void {
        $read = LedgerFile::decode($written);
        $read = $read->and($read);
    });

    expect($read->proofs())->toHaveCount(20_000)
        ->and($seconds)->toBeLessThan(Stopwatch::BOUND);
});
