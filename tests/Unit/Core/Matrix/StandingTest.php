<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Matrix\Standing;

it('spells each standing as the tests report writes it, and knows which it lists', function (): void {
    expect(array_map(static fn(Standing $standing): array => [$standing->value, $standing->isUseless()], Standing::cases()))->toBe([
        ['kills-nothing', true],
        ['never-first', true],
        ['useful', false],
        ['not-assessed', false],
    ]);
});

it('folds rows into a whole test that is useless only when every row is', function (Standing $one, Standing $other, Standing $whole): void {
    expect($one->and($other))->toBe($whole)
        ->and($other->and($one))->toBe($whole);
})->with([
    [Standing::Useful, Standing::KillsNothing, Standing::Useful],
    [Standing::Useful, Standing::NotAssessed, Standing::Useful],
    [Standing::NeverFirst, Standing::Useful, Standing::Useful],
    [Standing::NotAssessed, Standing::NotAssessed, Standing::NotAssessed],
    [Standing::NotAssessed, Standing::KillsNothing, Standing::KillsNothing],
    [Standing::NotAssessed, Standing::NeverFirst, Standing::NeverFirst],
    [Standing::KillsNothing, Standing::KillsNothing, Standing::KillsNothing],
    [Standing::KillsNothing, Standing::NeverFirst, Standing::NeverFirst],
    [Standing::NeverFirst, Standing::NeverFirst, Standing::NeverFirst],
]);
