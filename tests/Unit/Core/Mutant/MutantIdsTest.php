<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;

$id = static fn(int $occurrence): MutantId => MutantId::hash(Path::of('src/Money.php'), 'Plus', "-+\n+-", $occurrence);

it('holds nothing to begin with', function () use ($id): void {
    expect(MutantIds::none())->toHaveCount(0)
        ->and(MutantIds::none()->has($id(0)))->toBeFalse();
});

it('holds each id once, and says whether it holds one', function () use ($id): void {
    $ids = MutantIds::of($id(0), $id(1), $id(0));

    expect($ids)->toHaveCount(2)
        ->and($ids->has($id(0)))->toBeTrue()
        ->and($ids->has($id(1)))->toBeTrue()
        ->and($ids->has($id(2)))->toBeFalse();
});
