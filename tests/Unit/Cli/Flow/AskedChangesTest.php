<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\AskedChanges;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;

$measured = Revision::ref('0123456789abcdef0123456789abcdef01234567');

$money = static fn(int $line): Change => Change::modified(Path::of('src/Money.php'), Lines::of(Line::of($line)));

it('takes every change since the map\'s commit, and each since the ref asked that is not among them, each with the revision it changed since', function () use (
    $measured,
    $money,
): void {
    $price = Change::added(Path::of('src/Price.php'), Lines::none());
    $asked = AskedChanges::of(Changes::of($money(3)), $measured, Changes::of($money(7), $price), Revision::ref('origin/main'));

    expect($asked->since())->toEqual([[$money(3), $measured], [$price, Revision::ref('origin/main')]])
        ->and($asked->changes())->toEqual(Changes::of($money(3), $price));
});

it('takes only the change since the map\'s commit where no ref is asked', function () use ($measured, $money): void {
    $asked = AskedChanges::of(Changes::of($money(3)), $measured, Changes::of(Change::deleted(Path::of('src/Gone.php'))), NotGiven::value());

    expect($asked->since())->toEqual([[$money(3), $measured]]);
});
