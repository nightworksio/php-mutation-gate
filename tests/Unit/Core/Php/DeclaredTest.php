<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Php\Declared;

it('holds the lines of its body, from its opening brace\'s to its closing one\'s', function (): void {
    $declared = Declared::in(Path::of('src/Money.php'), 'add', Line::of(3), Line::of(5));

    expect(array_map(static fn(int $line): bool => $declared->holds(Line::of($line)), [2, 3, 5, 6]))
        ->toBe([false, true, true, false]);
});
