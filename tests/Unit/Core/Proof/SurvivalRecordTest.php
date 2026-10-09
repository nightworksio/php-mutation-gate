<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Proof\SurvivalRecord;
use NightWorksIO\MutationGate\Core\Pruning\Survival;
use NightWorksIO\MutationGate\Tests\Support\PruningCases;

it('writes each runner\'s windows as a 0 for each kill and a 1 for each mutant let through, and the last let through', function (): void {
    $survival = Survival::none()
        ->with(Name::of('pest'), PruningCases::window('Plus', '010', 'abc'))
        ->with(Name::of('pest'), PruningCases::window('Minus', '0'));

    expect(SurvivalRecord::of($survival))->toBe([
        'pest' => ['Minus' => ['outcomes' => '0'], 'Plus' => ['outcomes' => '010', 'last' => 'abc']],
    ])
        ->and(SurvivalRecord::read(Node::decode((string) json_encode(SurvivalRecord::of($survival)))))->toEqual($survival);
});

it('keeps each well-formed window and drops anything else', function (): void {
    $read = SurvivalRecord::read(Node::decode((string) json_encode([
        'pest' => [
            'Plus' => ['outcomes' => '01'],
            'Bad' => ['outcomes' => '0x1'],
            'Gone' => ['last' => 'a'],
            'Odd' => ['outcomes' => 5],
        ],
        'infection' => 'not a map',
    ])));

    expect(SurvivalRecord::of($read))->toBe(['pest' => ['Plus' => ['outcomes' => '01']]]);
});

it('learns nothing from a ledger without the section', function (): void {
    expect(SurvivalRecord::read(Node::decode('{}')->field(SurvivalRecord::SECTION)))->toEqual(Survival::none());
});
