<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Report\Mutator;

it('prints a mutator by the last segment of its name', function (string $name, string $short): void {
    expect(Mutator::short($name))->toBe($short);
})->with([
    'a class name' => ['Pest\\Mutate\\Mutators\\Equality\\LessToLessOrEqual', 'LessToLessOrEqual'],
    'a short name' => ['LessThan', 'LessThan'],
]);
