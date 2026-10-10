<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Recheck\NoRecheck;

it('says in one line why no survivor is re-checked, and whether a pull request\'s run expected one', function (
    NoRecheck $none,
    string $why,
    bool $expected,
): void {
    expect([$none->why(), $none->wasExpected()])->toBe([$why, $expected]);
})->with([
    'off' => [fn(): NoRecheck => NoRecheck::off(), 'survivorsFirst.max is 0, so no survivor is re-checked first.', false],
    'the default branch' => [fn(): NoRecheck => NoRecheck::onTheDefaultBranch(), 'A run of the default branch re-checks no survivors first.', false],
    'no scope' => [fn(): NoRecheck => NoRecheck::withoutAScope(), 'The run has no ref of its own, so it has no earlier run to re-check.', false],
    'no earlier run' => [fn(): NoRecheck => NoRecheck::noEarlierRun(), 'No earlier run of this branch to re-check.', true],
    'none left' => [fn(): NoRecheck => NoRecheck::noneLeft(), 'The last run of this branch left no survivor to re-check.', true],
]);
