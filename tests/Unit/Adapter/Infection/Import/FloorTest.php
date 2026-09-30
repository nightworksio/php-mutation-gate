<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\Import\Floor;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Floor as TreeFloor;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Tests\Support\Imports;

$said = static fn(string $settings): array => Imports::keys(Floor::imported(Node::decode($settings)));

it('takes minMsi as every tree\'s floor, truncated to two decimals', function () use ($said): void {
    expect(Floor::of(Node::decode('{"minMsi": 80.567, "minCoveredMsi": 90}')))->toEqual(TreeFloor::ofHundredths(8056))
        ->and($said('{"minMsi": 80.567}'))->toBe(['  minMsi: imported as the floor of every tree, 80.56']);
});

it('takes minCoveredMsi as the floor where minMsi is not set, and leaves uncovered mutants out either way', function () use ($said): void {
    $covered = Floor::imported(Node::decode('{"minCoveredMsi": 90}'));

    expect(Floor::of(Node::decode('{"minCoveredMsi": 90}')))->toEqual(TreeFloor::ofHundredths(9000))
        ->and($covered->layer()->floors()->uncovered())->toBe(Uncovered::Exclude)
        ->and($said('{"minCoveredMsi": 90}'))->toBe(['  minCoveredMsi: imported as uncovered: exclude, and the floor of every tree, 90.00'])
        ->and($said('{"minMsi": 80, "minCoveredMsi": 90}'))->toBe([
            '  minMsi: imported as the floor of every tree, 80.00',
            '  minCoveredMsi: imported as uncovered: exclude',
        ])
        ->and($said('{"minCoveredMsi": 0}'))->toBe(['  minCoveredMsi: imported as uncovered: exclude']);
});

it('drops a floor of 0 or one that is not a percentage, and sets none', function () use ($said): void {
    expect(Floor::of(Node::decode('{"minMsi": 0}')))->toEqual(Undeclared::floor())
        ->and(Floor::of(Node::decode('{"minMsi": 120}')))->toEqual(Undeclared::floor())
        ->and(Floor::of(Node::decode('{"minMsi": "80"}')))->toEqual(Undeclared::floor())
        ->and(Floor::of(Node::decode('{}')))->toEqual(Undeclared::floor())
        ->and($said('{"minMsi": 0}'))->toBe(['  minMsi: dropped, because a floor of 0 holds a tree to nothing'])
        ->and($said('{"minMsi": 120}'))->toBe(['  minMsi: dropped, because 120 is not a percentage'])
        ->and($said('{"minMsi": "80"}'))->toBe(['  minMsi: dropped, because "80" is not a percentage'])
        ->and($said('{}'))->toBe([]);
});
