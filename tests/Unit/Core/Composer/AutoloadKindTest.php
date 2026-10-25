<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Composer\AutoloadKind;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

it('maps a class to its file, psr-4 taking the prefix off and psr-0 keeping it, each _ of its short name a directory', function (): void {
    expect([
        AutoloadKind::Psr4->fileOf('App\Money\Cart_Line', 'App\\'),
        AutoloadKind::Psr4->fileOf('App\Cart', 'App\\'),
        AutoloadKind::Psr0->fileOf('Acme\Old_Money\Cart_Line', 'Acme\\'),
        AutoloadKind::Psr4->fileOf('Other\Cart', 'App\\'),
        AutoloadKind::Classmap->fileOf('App\Cart', 'App\\'),
    ])->toEqual([
        Paths::of(Path::of('Money/Cart_Line.php')),
        Paths::of(Path::of('Cart.php')),
        Paths::of(Path::of('Acme/Old_Money/Cart/Line.php')),
        Paths::none(),
        Paths::none(),
    ]);
});

it('maps a namespace to its directory, the prefix\'s own as the directory itself', function (): void {
    expect([
        AutoloadKind::Psr4->directoryOf('App\Money\Cart', 'App\\'),
        AutoloadKind::Psr4->directoryOf('App', 'App\\'),
        AutoloadKind::Psr0->directoryOf('Acme\Old', 'Acme\\'),
    ])->toEqual([
        Paths::of(Path::of('Money/Cart')),
        Paths::of(Path::of('.')),
        Paths::of(Path::of('Acme/Old')),
    ]);
});
