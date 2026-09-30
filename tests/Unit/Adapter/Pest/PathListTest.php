<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\PathList;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

it('joins its paths in order', function (): void {
    $list = PathList::of(Paths::of(Path::of('src/Money.php'), Path::of('src/Held')));

    expect($list->joined(','))->toBe('src/Money.php,src/Held')
        ->and(PathList::of(Paths::none())->joined(','))->toBe('');
});

it('knows a path with a comma, which Pest would split in two', function (): void {
    expect(PathList::of(Paths::of(Path::of('src/Money.php'), Path::of('src/a,b.php')))->holdsAComma())->toBeTrue()
        ->and(PathList::of(Paths::of(Path::of('src/Money.php'), Path::of('src/Held')))->holdsAComma())->toBeFalse()
        ->and(PathList::of(Paths::none())->holdsAComma())->toBeFalse();
});
