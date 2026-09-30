<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Gzip;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKill;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKills;
use NightWorksIO\MutationGate\Core\Order\Enclosing;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Order\Kills;
use NightWorksIO\MutationGate\Core\Order\RankedMutant;
use NightWorksIO\MutationGate\Core\Order\Ranking;
use NightWorksIO\MutationGate\Core\Proof\Bases;
use NightWorksIO\MutationGate\Core\Proof\Inputs;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\LedgerFile;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Proofs;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Timing;
use NightWorksIO\MutationGate\Core\Proof\Uncommitted;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Growth;
use NightWorksIO\MutationGate\Tests\Support\Moment;

$keyA = str_repeat('a', 64);
$keyB = str_repeat('b', 64);
$base = str_repeat('e', 64);
$killedId = MutantId::hash(Path::of('src/Money.php'), 'Plus', "-+\n+-", 0);
$minusId = MutantId::hash(Path::of('src/Money.php'), 'Minus', "-+\n+-", 0);
$survivedId = MutantId::hash(Path::of('src/Money.php'), 'LessThan', "-<\n+<=", 0);

// A killed mutant as a run reports it, in full.
$killedBy = static fn(MutantId $id, string $mutator, int $line): Mutant => Mutant::of(
    $id,
    sprintf('native-%d', $line),
    Location::of(Path::of('src/Money.php'), Line::of($line), Line::of($line)),
    Mutation::of($mutator, MutatorFamily::Arithmetic, "-+\n+-"),
    MutantStatus::Killed,
    Seconds::of(0.2),
);
// A killed mutant as a ledger reads it back: only its id, line, mutator and killers.
$proved = static fn(MutantId $id, string $mutator, int $line, TestIds $killers): ProvedKill => ProvedKill::of(
    $id,
    Path::of('src/Money.php'),
    Line::of($line),
    $mutator,
    $killers,
);
$killed = $killedBy($killedId, 'Plus', 44);
$survived = Mutant::of(
    $survivedId,
    '9a0b7e',
    Location::of(Path::of('src/Money.php'), Line::of(42), Line::of(42)),
    Mutation::of('LessThan', MutatorFamily::Boundary, "-<\n+<="),
    MutantStatus::Survived,
    Seconds::of(0.4),
);
$at = static fn(string $instant): Instant => Moment::at($instant);
$run = static fn(string $id, string $instant, string $at = ''): Run => Run::of($id, Moment::at($instant), Digest::of($at === '' ? $base : $at));

$ledgerOf = static fn(Proof $money): Ledger => Ledger::empty()
    ->withProof($money)
    ->withProof(Proof::of(Digest::of($keyB), Path::of('src/B.php'), Mutants::none(), $run('github:2/1', '2026-09-29T21:00:00Z')))
    ->withTiming(Timing::of(Path::of('src/Money.php'), Seconds::of(12.4), 'infection', $at('2026-09-29T20:48:17Z')))
    ->atBase(Digest::of($base))
    ->withPassed(Passed::of(Revision::ref('206b4e0c1f2a3b4c5d6e7f8091a2b3c4d5e6f708'), 'mutation-gate', 0))
    ->withKillers(KillHistory::none()
        ->withMutant($killedId, Ranking::of(
            Kills::of(TestId::of('MoneyTest::adds'), 3),
            Kills::of(TestId::of('TaxTest::rounds'), 1),
        ))
        ->withFunction(
            Enclosing::named(Path::of('src/Money.php'), 'add'),
            Ranking::of(Kills::of(TestId::of('TaxTest::rounds'), 2)),
        ));
$adds = TestIds::of(TestId::of('MoneyTest::adds'));
$both = TestIds::of(TestId::of('CartTest::totals'), TestId::of('MoneyTest::adds'));
// The ledger as a run leaves it: every mutant in full.
// What the proof of src/Money.php records of its inputs.
$uncommitted = Inputs::of(Digest::of(str_repeat('1', 64)), Digest::of(str_repeat('2', 64)))
    ->withTest(Path::of('tests/MoneyTest.php'), Digest::of(str_repeat('3', 64)));
$inputs = $uncommitted->takenAt(Revision::ref(str_repeat('4', 40)));
$ledger = $ledgerOf(Proof::of(Digest::of($keyA), Path::of('src/Money.php'), Mutants::of(
    $killed->killedBy($adds),
    $survived,
    $killedBy($minusId, 'Minus', 45)->killedBy($both),
    $killed,
), $run('github:1/1', '2026-09-29T20:48:17Z'))->withInputs($inputs));
// The same ledger read back: the survivor in full, and each kill as the ledger proved it.
$readBack = $ledgerOf(Proof::held(
    Digest::of($keyA),
    Path::of('src/Money.php'),
    Mutants::of($survived),
    ProvedKills::of(
        $proved($killedId, 'Plus', 44, $adds),
        $proved($minusId, 'Minus', 45, $both),
        $proved($killedId, 'Plus', 44, TestIds::none()),
    ),
    $run('github:1/1', '2026-09-29T20:48:17Z'),
)->withInputs($inputs));

// The ledger's file as data, to change one entry of and write back.
$data = static function () use ($ledger): array {
    $json = Gzip::unpack(LedgerFile::encode($ledger), 'the ledger');
    $data = is_string($json) ? json_decode($json, associative: true) : [];

    return is_array($data) ? $data : [];
};
$written = static fn(array $file): string => Gzip::pack(JsonText::compact($file));

it('writes compact JSON, gzipped: the newest proofs first, killed mutants as tuples with their killers, survivors in full, and the kill history', function () use ($ledger, $keyA, $keyB, $base, $killedId, $minusId, $survivedId): void {
    expect(Gzip::unpack(LedgerFile::encode($ledger), 'the ledger'))->toBe(JsonText::compact([
        'format' => 3,
        'bases' => [$base],
        'mutators' => ['Plus', 'Minus'],
        'tests' => ['MoneyTest::adds', 'CartTest::totals', 'TaxTest::rounds'],
        'inputs' => [
            'mutation' => [str_repeat('2', 64)],
            'tests' => [['tests/MoneyTest.php', str_repeat('3', 64)]],
            'commits' => [str_repeat('4', 40)],
        ],
        'proofs' => [
            $keyB => ['unit' => 'src/B.php', 'base' => $base, 'at' => '2026-09-29T21:00:00Z', 'run' => 'github:2/1', 'mutants' => []],
            $keyA => [
                'unit' => 'src/Money.php',
                'base' => $base,
                'at' => '2026-09-29T20:48:17Z',
                'run' => 'github:1/1',
                'mutants' => [
                    [$killedId->value(), 44, 0, [0]],
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
                    [$minusId->value(), 45, 1, [1, 0]],
                    [$killedId->value(), 44, 0, []],
                ],
                'digests' => ['source' => str_repeat('1', 64), 'mutation' => 0, 'tests' => [0], 'commit' => 0],
            ],
        ],
        'timings' => ['src/Money.php' => ['seconds' => 12.4, 'runner' => 'infection', 'at' => '2026-09-29T20:48:17Z']],
        'killers' => [
            'mutants' => [$killedId->value() => [[0, 3], [2, 1]]],
            'functions' => ['src/Money.php' => ['add' => [[2, 2]]]],
        ],
        'passed' => ['commit' => '206b4e0c1f2a3b4c5d6e7f8091a2b3c4d5e6f708', 'check' => 'mutation-gate', 'ownScopeProofs' => 0],
    ]));
});

it('writes an empty ledger as empty lists and maps and no passing commit', function (): void {
    expect(Gzip::unpack(LedgerFile::encode(Ledger::empty()), 'the ledger'))
        ->toBe('{"format":3,"bases":[],"mutators":[],"tests":[],"inputs":{"mutation":[],"tests":[],"commits":[]},"proofs":{},"timings":{},"killers":{"mutants":{},"functions":{}}}');
});

it('reads back the ledger it wrote, each killed mutant as the kill it proves', function () use ($ledger, $readBack): void {
    expect(LedgerFile::decode(LedgerFile::encode($ledger)))->toEqual($readBack)
        ->and(LedgerFile::decode(LedgerFile::encode($readBack)))->toEqual($readBack);
});

it('keeps two proofs as new as each other in the order it held them', function () use ($run, $base): void {
    $proof = static fn(string $key): Proof => Proof::of(Digest::of($key), Path::of('src/A.php'), Mutants::none(), $run('local', '2026-09-29T20:00:00Z'));
    $ledger = Ledger::empty()->withProof($proof(str_repeat('c', 64)))->withProof($proof(str_repeat('1', 64)))->atBase(Digest::of($base));

    $json = Gzip::unpack(LedgerFile::encode($ledger), 'the ledger');

    expect(array_keys(Node::decode(is_string($json) ? $json : '')->field('proofs')->entries()))
        ->toBe([str_repeat('c', 64), str_repeat('1', 64)]);
});

it('keeps only the proofs of the five bases its runs saw most recently', function () use ($run): void {
    $bases = array_map(static fn(int $at): string => hash('sha256', sprintf('base %d', $at)), range(1, 6));
    $ledger = Ledger::empty();

    foreach ($bases as $base) {
        $ledger = $ledger
            ->withProof(Proof::of(Digest::of(hash('sha256', $base)), Path::of('src/A.php'), Mutants::none(), $run('local', '2026-09-29T20:00:00Z', $base)))
            ->atBase(Digest::of($base));
    }

    $read = LedgerFile::decode(LedgerFile::encode($ledger->atBase(Digest::of($bases[2]))));

    expect($read->bases())->toEqual(Bases::of(...array_map(Digest::of(...), [$bases[2], $bases[5], $bases[4], $bases[3], $bases[1]])))
        ->and($read->proofs())->toHaveCount(5)
        ->and($read->proofs()->has(Digest::of(hash('sha256', $bases[0]))))->toBeFalse()
        ->and($read->provesAt(Digest::of($bases[1])))->toBeTrue()
        ->and($read->provesAt(Digest::of($bases[0])))->toBeFalse();
});

it('keeps at most the newest twenty thousand proofs of its bases', function () use ($run, $base): void {
    $proofs = [Proof::of(Digest::of(str_repeat('0', 64)), Path::of('src/Old.php'), Mutants::none(), $run('old', '2026-01-01T00:00:00Z'))];

    for ($made = 1; $made <= 20_000; $made++) {
        $proofs[] = Proof::of(Digest::of(hash('sha256', sprintf('%d', $made))), Path::of('src/New.php'), Mutants::none(), $run('new', '2026-09-29T20:00:00Z'));
    }

    $kept = LedgerFile::decode(LedgerFile::encode(Ledger::empty()->withProofs(Proofs::of(...$proofs))->atBase(Digest::of($base))))->proofs();

    expect($kept)->toHaveCount(20_000)
        ->and($kept->has(Digest::of(str_repeat('0', 64))))->toBeFalse()
        ->and($kept->has(Digest::of(hash('sha256', '20000'))))->toBeTrue();
});

it('reads a ledger of the second format as it is, its proofs recording no digests of their inputs', function () use ($written, $readBack): void {
    $undigested = $readBack->withProof(Proof::held(
        Digest::of(str_repeat('a', 64)),
        Path::of('src/Money.php'),
        Mutants::none(),
        ProvedKills::none(),
        Run::of('github:1/1', Moment::at('2026-09-29T20:48:17Z'), Digest::of(str_repeat('e', 64))),
    ));
    $json = Gzip::unpack(LedgerFile::encode($undigested), 'the ledger');
    $file = is_string($json) ? json_decode($json, associative: true) : [];
    $read = LedgerFile::decode($written(array_replace(is_array($file) ? $file : [], ['format' => 2])));

    expect($read->proofs()->proofFor(Digest::of(str_repeat('a', 64))))->toEqual($undigested->proofs()->proofFor(Digest::of(str_repeat('a', 64))))
        ->and(count($read->proofs()))->toBe(2);
});

it('reads a file of another format, or no ledger at all, as an empty ledger', function (string $file): void {
    expect(LedgerFile::decode($file))->toEqual(Ledger::empty());
})->with([
    'the first format' => [Gzip::pack('{"format": 1, "proofs": {}, "timings": {}, "passed": "206b4e0"}')],
    'the fourth format' => [Gzip::pack('{"format": 4, "bases": [], "mutators": [], "proofs": {}, "timings": {}}')],
    'the second format, not gzipped' => ['{"format": 2, "bases": [], "mutators": [], "proofs": {}, "timings": {}, "passed": "206b4e0"}'],
    'a format written as text' => [Gzip::pack('{"format": "2", "passed": "206b4e0"}')],
    'no format' => [Gzip::pack('{"passed": "206b4e0"}')],
    'text that is not JSON' => [Gzip::pack('{"format": 2, "passed": ')],
    'a gzip stream cut short' => [substr(Gzip::pack('{"format": 2, "passed": "206b4e0"}'), 0, 20)],
    'nothing' => [''],
]);

it('writes no commit for inputs that stand for none, and reads them back so', function () use ($run, $keyA, $base, $uncommitted): void {
    $proof = Proof::of(Digest::of($keyA), Path::of('src/Money.php'), Mutants::of(), $run('github:1/1', '2026-09-29T20:48:17Z'))
        ->withInputs($uncommitted);
    $written = LedgerFile::encode(Ledger::empty()->withProof($proof)->atBase(Digest::of($base)));
    $read = LedgerFile::decode($written)->proofs()->proofFor(Digest::of($keyA));

    expect($read instanceof Proof ? $read->inputs() : $read)->toEqual($uncommitted)
        ->and($uncommitted->commit())->toEqual(Uncommitted::tree())
        ->and(Gzip::unpack($written, 'the ledger'))->not->toContain('"commit"');
});

it('drops every proof that points into a list of the ledger\'s inputs whose entries are not all well formed', /** @param array<int, mixed> $entries */ function (string $list, array $entries) use ($data, $written, $ledger, $keyA): void {
    $file = $data();
    $file['inputs'] = [...['mutation' => [str_repeat('2', 64)], 'tests' => [['tests/MoneyTest.php', str_repeat('3', 64)]], 'commits' => [str_repeat('4', 40)]], $list => $entries];

    expect(LedgerFile::decode($written($file)))->toEqual($ledger->withoutProof(Digest::of($keyA)));
})->with([
    'a mutation digest that is not a SHA-256' => ['mutation', [str_repeat('2', 64), 'abc']],
    'a test file with no digest' => ['tests', [['tests/MoneyTest.php', str_repeat('3', 64)], ['tests/TaxTest.php']]],
    'a test file whose digest is not a SHA-256' => ['tests', [['tests/MoneyTest.php', 'abc']]],
    'a commit that is not a full commit id' => ['commits', [str_repeat('4', 40), 'HEAD']],
]);

it('drops a proof that is not well formed and keeps the rest', function (Closure $spoil) use ($data, $written, $ledger, $keyA, $keyB): void {
    $file = $data();
    $file['proofs'] = $spoil($file['proofs'], $keyA);

    expect(LedgerFile::decode($written($file)))->toEqual($ledger->withoutProof(Digest::of($keyA)))
        ->and(LedgerFile::decode($written($file))->proofs()->has(Digest::of($keyB)))->toBeTrue();
})->with([
    'a key that is not a SHA-256' => [static fn(array $proofs, string $key): array => [...$proofs, strtoupper($key) => $proofs[$key], $key => null]],
    'a unit that is not text' => [static fn(array $proofs, string $key): array => array_replace_recursive($proofs, [$key => ['unit' => null]])],
    'a base that is not a SHA-256' => [static fn(array $proofs, string $key): array => array_replace_recursive($proofs, [$key => ['base' => 'EEEE']])],
    'a base that is not text' => [static fn(array $proofs, string $key): array => array_replace_recursive($proofs, [$key => ['base' => null]])],
    'an instant that is not one' => [static fn(array $proofs, string $key): array => array_replace_recursive($proofs, [$key => ['at' => 'yesterday']])],
    'no run' => [static fn(array $proofs, string $key): array => array_replace_recursive($proofs, [$key => ['run' => 5]])],
    'mutants that are not a list' => [static fn(array $proofs, string $key): array => array_replace_recursive($proofs, [$key => ['mutants' => 'none']])],
    'a killed mutant whose killers are not a list' => [static fn(array $proofs, string $key): array => array_replace_recursive($proofs, [$key => ['mutants' => [0 => [3 => null]]]])],
    'a killed mutant of five fields' => [static fn(array $proofs, string $key): array => array_replace_recursive($proofs, [$key => ['mutants' => [0 => [4 => 'more']]]])],
    'a killed mutant killed by a test not listed' => [static fn(array $proofs, string $key): array => array_replace_recursive($proofs, [$key => ['mutants' => [0 => [3 => [0 => 7]]]]])],
    'a source digest that is not a SHA-256' => [static fn(array $proofs, string $key): array => array_replace_recursive($proofs, [$key => ['digests' => ['source' => 'abc']]])],
    'a mutation digest that is no index' => [static fn(array $proofs, string $key): array => array_replace_recursive($proofs, [$key => ['digests' => ['mutation' => null]]])],
    'a mutation digest past the ledger\'s inputs' => [static fn(array $proofs, string $key): array => array_replace_recursive($proofs, [$key => ['digests' => ['mutation' => 1]]])],
    'a test digest past the ledger\'s inputs' => [static fn(array $proofs, string $key): array => array_replace_recursive($proofs, [$key => ['digests' => ['tests' => [0 => 5]]]])],
    'a commit that is no index' => [static fn(array $proofs, string $key): array => array_replace_recursive($proofs, [$key => ['digests' => ['commit' => 'HEAD']]])],
    'a commit past the ledger\'s inputs' => [static fn(array $proofs, string $key): array => array_replace_recursive($proofs, [$key => ['digests' => ['commit' => 4]]])],
    'test digests that are not a map' => [static fn(array $proofs, string $key): array => array_replace_recursive($proofs, [$key => ['digests' => ['tests' => 'none']]])],
    'a killed mutant whose id is not one' => [static fn(array $proofs, string $key): array => array_replace_recursive($proofs, [$key => ['mutants' => [0 => [0 => 'xyz']]]])],
    'a killed mutant on no line' => [static fn(array $proofs, string $key): array => array_replace_recursive($proofs, [$key => ['mutants' => [0 => [1 => 0]]]])],
    'a killed mutant of a mutator there is not' => [static fn(array $proofs, string $key): array => array_replace_recursive($proofs, [$key => ['mutants' => [0 => [2 => 2]]]])],
    'a killed mutant kept in full' => [static fn(array $proofs, string $key): array => array_replace_recursive($proofs, [$key => ['mutants' => [1 => ['status' => 'killed']]]])],
    'a full record with a family there is not' => [static fn(array $proofs, string $key): array => array_replace_recursive($proofs, [$key => ['mutants' => [1 => ['family' => 'nope']]]])],
]);

it('drops every proof, and the kill history, that point into a list whose names are not all names', /** @param array<int|string, int|string> $names */ function (string $list, array $names) use ($data, $written, $ledger, $keyA): void {
    $file = [...$data(), $list => $names];
    $dropped = $ledger->withoutProof(Digest::of($keyA));

    expect(LedgerFile::decode($written($file)))
        ->toEqual($list === 'tests' ? $dropped->withKillers(KillHistory::none()) : $dropped);
})->with([
    'a mutator that is not text' => ['mutators', ['Plus', 7]],
    'mutators not in a list' => ['mutators', ['plus' => 'Plus']],
    'a test that is not text' => ['tests', ['MoneyTest::adds', 7]],
    'tests not in a list' => ['tests', ['adds' => 'MoneyTest::adds']],
]);

it('drops a base that is not a SHA-256, and reads bases that are not a list as none', function () use ($data, $written, $base): void {
    expect(LedgerFile::decode($written([...$data(), 'bases' => ['nope', $base, 7]]))->bases())->toEqual(Bases::of(Digest::of($base)))
        ->and(LedgerFile::decode($written([...$data(), 'bases' => 'none']))->bases())->toEqual(Bases::none());
});

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

it('reads proofs and timings that are not maps as none, and keeps what else it holds', function () use ($data, $written, $base, $ledger): void {
    $file = [...$data(), 'proofs' => 7, 'timings' => 'none'];

    expect(LedgerFile::decode($written($file)))->toEqual(Ledger::empty()->atBase(Digest::of($base))->withPassed(Passed::of(Revision::ref('206b4e0c1f2a3b4c5d6e7f8091a2b3c4d5e6f708'), 'mutation-gate', 0))->withKillers($ledger->killers()));
});

it('drops each kill pair that is not well formed, a ranking left with none, and an entry under no name', function () use ($data, $written, $killedId): void {
    $file = $data();
    $file['killers'] = [
        'mutants' => [
            $killedId->value() => [[0, 3], [9, 1], [1, 0], [1, 2, 3], ['0', 1], 'pair', [2, 1]],
            'not an id' => [[0, 1]],
            str_repeat('f', 12) => [[9, 1]],
        ],
        'functions' => [
            'src/Money.php' => ['add' => [[1, 1]], 'none' => [[0, -1]], 'many' => 'pairs'],
            '' => ['add' => [[0, 1]]],
            'src/Cart.php' => 'functions',
        ],
    ];

    expect(LedgerFile::decode($written($file))->killers())->toEqual(KillHistory::none()
        ->withMutant($killedId, Ranking::of(
            Kills::of(TestId::of('MoneyTest::adds'), 3),
            Kills::of(TestId::of('TaxTest::rounds'), 1),
        ))
        ->withFunction(
            Enclosing::named(Path::of('src/Money.php'), 'add'),
            Ranking::of(Kills::of(TestId::of('CartTest::totals'), 1)),
        ))
        ->and(LedgerFile::decode($written([...$data(), 'killers' => ['mutants' => 7, 'functions' => []]]))->killers())
        ->toEqual(KillHistory::none());
});

it('writes the kill history of the mutants its kept proofs hold, and of every function', function () use ($ledger, $killedId): void {
    $gone = MutantId::hash(Path::of('src/Gone.php'), 'Plus', "-+\n+-", 0);
    $history = $ledger->killers()->withMutant($gone, Ranking::of(Kills::of(TestId::of('GoneTest::goes'), 1)));

    $read = LedgerFile::decode(LedgerFile::encode($ledger->withKillers($history)))->killers();

    expect(array_map(static fn(RankedMutant $ranked): string => $ranked->mutant()->value(), [...$read->mutants()]))
        ->toBe([$killedId->value()]);
});

it('reads a passing record that is not well formed as none', function (array|int|string $passed) use ($data, $written, $readBack): void {
    $file = [...$data(), 'passed' => $passed];
    $read = LedgerFile::decode($written($file));

    expect($read->lastPassed())->toEqual(Ledger::empty()->lastPassed())
        ->and($read->proofs())->toEqual($readBack->proofs());
})->with([
    'a bare commit' => ['206b4e0c1f2a3b4c5d6e7f8091a2b3c4d5e6f708'],
    'a number' => [7],
    'a commit that is not text' => [['commit' => 7, 'check' => 'mutation-gate', 'ownScopeProofs' => 0]],
    'a commit git would read as an option' => [['commit' => '--output=/tmp/x', 'check' => 'mutation-gate', 'ownScopeProofs' => 0]],
    'a commit that is not its full id' => [['commit' => '206b4e0', 'check' => 'mutation-gate', 'ownScopeProofs' => 0]],
    'a check that is not text' => [['commit' => '206b4e0c1f2a3b4c5d6e7f8091a2b3c4d5e6f708', 'check' => null, 'ownScopeProofs' => 0]],
    'no count of own proofs' => [['commit' => '206b4e0c1f2a3b4c5d6e7f8091a2b3c4d5e6f708', 'check' => 'mutation-gate']],
    'a count below none' => [['commit' => '206b4e0c1f2a3b4c5d6e7f8091a2b3c4d5e6f708', 'check' => 'mutation-gate', 'ownScopeProofs' => -1]],
    'a count that is not whole' => [['commit' => '206b4e0c1f2a3b4c5d6e7f8091a2b3c4d5e6f708', 'check' => 'mutation-gate', 'ownScopeProofs' => 1.5]],
]);

it('reads back a passing verdict that used proofs of its own scope', function () use ($ledger): void {
    $passed = Passed::of(Revision::ref('5eeca8f0a1b2c3d4e5f60718293a4b5c6d7e8f90'), 'mutation / verdict', 3);

    expect(LedgerFile::decode(LedgerFile::encode($ledger->withPassed($passed)))->lastPassed())->toEqual($passed);
});

it('reads a full ledger and joins it to another in time linear in its proofs', function () use ($run, $killed, $base): void {
    $read = static function (int $size) use ($run, $killed, $base): Closure {
        $proofs = [];

        for ($made = 1; $made <= $size; $made++) {
            $proofs[] = Proof::of(Digest::of(hash('sha256', sprintf('%d', $made))), Path::of(sprintf('src/F%d.php', $made)), Mutants::of($killed), $run('new', '2026-09-29T20:00:00Z'));
        }

        $written = LedgerFile::encode(Ledger::empty()->withProofs(Proofs::of(...$proofs))->atBase(Digest::of($base)));

        return static function () use ($written): Ledger {
            $read = LedgerFile::decode($written);

            return $read->and($read);
        };
    };

    expect($read(10)()->proofs())->toHaveCount(10)
        ->and(Growth::of(625, $read))->toBeLessThan(Growth::LINEAR);
});

it('writes a full ledger of proved kills in time linear in its proofs', function () use ($run, $killedId, $base): void {
    $write = static function (int $size) use ($run, $killedId, $base): Closure {
        $proofs = [];

        for ($made = 1; $made <= $size; $made++) {
            $proofs[] = Proof::held(
                Digest::of(hash('sha256', sprintf('%d', $made))),
                Path::of(sprintf('src/F%d.php', $made)),
                Mutants::none(),
                ProvedKills::of(ProvedKill::of(
                    $killedId,
                    Path::of(sprintf('src/F%d.php', $made)),
                    Line::of(1),
                    sprintf('M%d', $made),
                    TestIds::of(TestId::of(sprintf('F%dTest::kills', $made)), TestId::of('MoneyTest::adds')),
                )),
                $run('new', '2026-09-29T20:00:00Z'),
            );
        }

        $ledger = Ledger::empty()->withProofs(Proofs::of(...$proofs))->atBase(Digest::of($base));

        return static fn(): string => LedgerFile::encode($ledger);
    };

    expect(LedgerFile::decode($write(10)())->proofs())->toHaveCount(10)
        ->and(Growth::of(2500, $write))->toBeLessThan(Growth::LINEAR);
});
