<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Analysis\Finding;
use NightWorksIO\MutationGate\Core\Analysis\Rejection;
use NightWorksIO\MutationGate\Core\File\Path;

it('names the analyser that rejected a mutant, the error it found and the file that error sits in', function (): void {
    $finding = Finding::error(Path::of('src/Wallet.php'), 'return.type', 'Method Wallet::total() should return int but returns string.');
    $rejection = Rejection::by('phpstan', $finding);

    expect($rejection->analyser())->toBe('phpstan')
        ->and($rejection->finding())->toEqual($finding)
        ->and($rejection->finding()->file())->toEqual(Path::of('src/Wallet.php'));
});

it('keeps a code and a message cut to their length, ending in an ellipsis, and a short one whole', function (): void {
    $rejection = Rejection::by('psalm', Finding::error(Path::of('src/Money.php'), str_repeat('c', 200), str_repeat('m', 1_000_000)));

    expect(mb_strlen($rejection->finding()->code()))->toBe(128)
        ->and($rejection->finding()->code())->toEndWith('c…')
        ->and(mb_strlen($rejection->finding()->message()))->toBe(1024)
        ->and($rejection->finding()->message())->toEndWith('m…')
        ->and(Rejection::by('psalm', Finding::error(Path::of('src/Money.php'), str_repeat('c', 128), str_repeat('é', 1024)))->finding())
        ->toEqual(Finding::error(Path::of('src/Money.php'), str_repeat('c', 128), str_repeat('é', 1024)));
});
