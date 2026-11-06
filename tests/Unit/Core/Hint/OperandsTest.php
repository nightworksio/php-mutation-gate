<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Hint\Operands;
use NightWorksIO\MutationGate\Core\NotGiven;

$sides = static fn(Operands|NotGiven $operands): array => $operands instanceof Operands
    ? [$operands->left, $operands->operator, $operands->right]
    : [];

it('splits an expression at the first of its operators outside every bracket', function () use ($sides): void {
    expect($sides(Operands::of('max($a, $b < 1) < $limit', ['<'])))->toBe(['max($a, $b < 1)', '<', '$limit'])
        ->and($sides(Operands::of('$a<10', ['<', '>'])))->toBe(['$a', '<', '10'])
        ->and($sides(Operands::of('[$a > 1] > $b', ['>'])))->toBe(['[$a > 1]', '>', '$b'])
        ->and($sides(Operands::of('ready($a < 1)', ['<'])))->toBe([]);
});
