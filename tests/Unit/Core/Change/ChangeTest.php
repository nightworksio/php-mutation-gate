<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\ChangeKind;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;

it('is an added file with its lines', function (): void {
    $change = Change::added(Path::of('src/New.php'), Lines::of(Line::of(1), Line::of(2)));

    expect($change->kind())->toBe(ChangeKind::Added)
        ->and($change->path()->value())->toBe('src/New.php')
        ->and($change->previousPath()->value())->toBe('src/New.php')
        ->and($change->lines())->toEqual(Lines::of(Line::of(1), Line::of(2)));
});

it('is a modified file with the lines added or changed on its new side', function (): void {
    $change = Change::modified(Path::of('src/Money.php'), Lines::of(Line::of(42)));

    expect($change->kind())->toBe(ChangeKind::Modified)
        ->and($change->path()->value())->toBe('src/Money.php')
        ->and($change->previousPath()->value())->toBe('src/Money.php')
        ->and($change->lines())->toEqual(Lines::of(Line::of(42)));
});

it('is a deleted file, which changes no line', function (): void {
    $change = Change::deleted(Path::of('src/Old.php'));

    expect($change->kind())->toBe(ChangeKind::Deleted)
        ->and($change->path()->value())->toBe('src/Old.php')
        ->and($change->previousPath()->value())->toBe('src/Old.php')
        ->and($change->lines())->toEqual(Lines::none());
});

it('is a renamed file, with the path it had and the lines it changed', function (): void {
    $change = Change::renamed(Path::of('src/Old.php'), Path::of('src/New.php'), Lines::none());

    expect($change->kind())->toBe(ChangeKind::Renamed)
        ->and($change->path()->value())->toBe('src/New.php')
        ->and($change->previousPath()->value())->toBe('src/Old.php')
        ->and($change->lines())->toEqual(Lines::none());
});
