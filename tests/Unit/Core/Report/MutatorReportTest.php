<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cost\MutatorTallies;
use NightWorksIO\MutationGate\Core\Report\MutatorReport;
use NightWorksIO\MutationGate\Core\Report\MutatorSchema;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\Schema;
use NightWorksIO\MutationGate\Tests\Support\Tallies;

$tallies = static fn(): MutatorTallies => Tallies::holding(
    Tallies::of('pest', 'Minus', 4, 0, 0, 4, 0.0),
    Tallies::of('pest', 'Plus', 10, 8, 2, 0, 6.0),
    Tallies::of('infection', 'ArrayItemRemoval', 5, 5, 0, 0, 2.0),
);

it('prints a row for each mutator of each runner, the costliest first, names flush left and figures flush right', function () use ($tallies): void {
    expect(MutatorReport::text($tallies()))->toBe(implode("\n", [
        'Runner     Mutator           Mutants  Kill rate  Survivors  Time  Per kill  Share',
        'pest       Plus                   10        80%          2  6.0s      0.8s    75%',
        'infection  ArrayItemRemoval        5       100%          0  2.0s      0.4s    25%',
        'pest       Minus                   4          -          0  0.0s         -     0%',
        '',
    ]));
});

it('says the ledger tallies no mutator yet where it tallies none', function (): void {
    expect(MutatorReport::text(MutatorTallies::none()))->toBe(sprintf("%s\n", MutatorReport::NONE))
        ->and(Decoded::at(MutatorReport::json(MutatorTallies::none())))->toBe(['format' => 1, 'seconds' => 0.0, 'mutators' => []]);
});

it('writes each mutator\'s counts, time and rates as JSON, leaving out a rate there is nothing to rate by', function () use ($tallies): void {
    expect(Decoded::at(MutatorReport::json($tallies()), 'mutators'))->toBe([
        ['runner' => 'pest', 'mutator' => 'Plus', 'mutants' => 10, 'killed' => 8, 'survived' => 2, 'ignored' => 0, 'seconds' => 6.0, 'killRate' => 80.0, 'secondsPerKill' => 0.75, 'share' => 75.0],
        ['runner' => 'infection', 'mutator' => 'ArrayItemRemoval', 'mutants' => 5, 'killed' => 5, 'survived' => 0, 'ignored' => 0, 'seconds' => 2.0, 'killRate' => 100.0, 'secondsPerKill' => 0.4, 'share' => 25.0],
        ['runner' => 'pest', 'mutator' => 'Minus', 'mutants' => 4, 'killed' => 0, 'survived' => 0, 'ignored' => 4, 'seconds' => 0.0, 'share' => 0.0],
    ]);
});

it('writes what its committed schema describes, and commits the schema as it is generated', function () use ($tallies): void {
    $schema = Schema::at('resources/mutators.schema.json');

    expect((string) file_get_contents($schema))->toBe(sprintf("%s\n", MutatorSchema::json()), 'resources/mutators.schema.json is out of date. Run composer report:schema and commit it.')
        ->and(Schema::errors(MutatorReport::json($tallies()), $schema))->toBe([])
        ->and(Schema::errors(MutatorReport::json(MutatorTallies::none()), $schema))->toBe([]);
});

it('names no more than the ten costliest mutators for the summary a dashboard reads', function (): void {
    $many = Tallies::holding(...array_map(static fn(int $at): object => Tallies::of('pest', sprintf('M%02d', $at), 1, 1, 0, 0, (float) $at), range(1, 11)));

    expect(MutatorReport::costliest($many))->toHaveCount(MutatorReport::COSTLIEST)
        ->and(MutatorReport::costliest($many)[0])->toBe(['runner' => 'pest', 'mutator' => 'M11', 'seconds' => 11.0, 'survived' => 0, 'killRate' => 100.0]);
});
