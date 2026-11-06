<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cost\MutatorTallies;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Proof\TallyRecord;
use NightWorksIO\MutationGate\Tests\Support\Tallies;

it('writes each runner\'s tallies by mutator, and reads them back', function (): void {
    $tallies = Tallies::holding(
        Tallies::of('pest', 'Plus', 10, 6, 2, 1, 4.5),
        Tallies::of('pest', 'Minus', 3, 3, 0, 0, 1.0),
    );

    expect(TallyRecord::of($tallies))->toBe([
        'pest' => [
            'Minus' => ['mutants' => 3, 'killed' => 3, 'survived' => 0, 'ignored' => 0, 'seconds' => 1.0],
            'Plus' => ['mutants' => 10, 'killed' => 6, 'survived' => 2, 'ignored' => 1, 'seconds' => 4.5],
        ],
    ])
        ->and(TallyRecord::read(Node::decode((string) json_encode(TallyRecord::of($tallies)))))->toEqual($tallies);
});

it('keeps each well-formed tally and drops anything else', function (): void {
    $read = TallyRecord::read(Node::decode((string) json_encode([
        'pest' => [
            'Plus' => ['mutants' => 2, 'killed' => 1, 'survived' => 1, 'ignored' => 0, 'seconds' => 1],
            'Short' => ['mutants' => 2, 'killed' => 1],
            'Over' => ['mutants' => 1, 'killed' => 1, 'survived' => 1, 'ignored' => 0, 'seconds' => 1],
            'Text' => ['mutants' => 'two', 'killed' => 1, 'survived' => 1, 'ignored' => 0, 'seconds' => 1],
        ],
        'infection' => 'not a map',
    ])));

    expect(TallyRecord::of($read))->toBe([
        'pest' => ['Plus' => ['mutants' => 2, 'killed' => 1, 'survived' => 1, 'ignored' => 0, 'seconds' => 1.0]],
    ]);
});

it('tallies nothing from a ledger without the section', function (): void {
    expect(TallyRecord::read(Node::decode('{}')->field(TallyRecord::SECTION)))->toEqual(MutatorTallies::none());
});
