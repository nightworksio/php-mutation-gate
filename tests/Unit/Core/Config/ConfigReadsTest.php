<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\ConfigReads;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;

it('reads no other file, or the files it names, each once', function (): void {
    expect(ConfigReads::none()->files())->toEqual(Paths::none())
        ->and(ConfigReads::none()->unnamedBecause())->toEqual(NotGiven::value())
        ->and(ConfigReads::named(Path::of('a.php'), Path::of('b.php'), Path::of('a.php'))->files())
        ->toEqual(Paths::of(Path::of('a.php'), Path::of('b.php')));
});

it('says why it reads a file it cannot name, and keeps the first reason where two are joined', function (): void {
    $first = ConfigReads::unnamed('It reads a.json.');
    $joined = ConfigReads::named(Path::of('a.php'))->and($first)->and(ConfigReads::unnamed('It reads b.json.'));

    expect($first->unnamedBecause())->toBe('It reads a.json.')
        ->and($joined->unnamedBecause())->toBe('It reads a.json.')
        ->and($joined->files())->toEqual(Paths::of(Path::of('a.php')))
        ->and(ConfigReads::named(Path::of('a.php'))->and(ConfigReads::named(Path::of('b.php')))->files())
        ->toEqual(Paths::of(Path::of('a.php'), Path::of('b.php')));
});
