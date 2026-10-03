<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Php\NamedFiles;

it('reaches every file the files name, in turn, each once, in the order reached, past a cycle', function (): void {
    $naming = NamedFiles::of([
        'a.php' => ['b.php', 'c.php'],
        'b.php' => ['d.php', 'a.php'],
        'c.php' => ['d.php'],
        'e.php' => ['a.php'],
    ]);

    expect($naming->reachedFrom('a.php'))->toBe(['a.php', 'b.php', 'c.php', 'd.php'])
        ->and($naming->reachedFrom('d.php', 'c.php', 'd.php'))->toBe(['d.php', 'c.php'])
        ->and($naming->reachedFrom('unnamed.php'))->toBe(['unnamed.php'])
        ->and($naming->reachedFrom())->toBe([]);
});
