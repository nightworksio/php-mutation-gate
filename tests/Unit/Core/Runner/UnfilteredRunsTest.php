<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\UnfilteredRuns;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;

it('keeps the test files each mutant\'s run loaded with no filter, or every one, and none for a mutant whose run ran a filter', function (): void {
    $narrowed = MutantId::hash(Path::of('src/Money.php'), 'Plus', '-a', 0);
    $whole = MutantId::hash(Path::of('src/Money.php'), 'Minus', '-b', 0);
    $filtered = MutantId::hash(Path::of('src/Money.php'), 'Times', '-c', 0);
    $files = Paths::of(Path::of('tests/MoneyTest.php'));
    $runs = UnfilteredRuns::none()->with($narrowed, $files)->with($whole, WholeSuite::tests());

    expect($runs->of($narrowed))->toBe($files)
        ->and($runs->of($whole))->toBeInstanceOf(WholeSuite::class)
        ->and($runs->of($filtered))->toBeInstanceOf(NotGiven::class);
});
