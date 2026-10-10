<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Fingerprint;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\TreeUnits;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;

$files = static function (string ...$paths): Fingerprints {
    $files = Fingerprints::none();

    foreach ($paths as $path) {
        $files = $files->with(Fingerprint::of(Path::of($path), Digest::of('9c1e')));
    }

    return $files;
};
$names = static fn(Units $units): array => array_map(
    static fn(Unit $unit): string => sprintf('%s%s', $unit->path()->value(), $unit->isHeld() ? ' (held)' : ''),
    iterator_to_array($units, preserve_keys: true),
);
$root = static fn(): Package => Package::at(Path::root());
$makeTrees = static fn(): Trees => Trees::of(
    Tree::at(Path::of('src'), Floor::of(100), $root()),
    Tree::at(Path::of('src/Generated'), Exempt::because('Generated code'), $root()),
    Tree::at(Path::of('lib'), Floor::of(80), $root()),
);

it('makes a unit of each held path, then of each PHP file in a tree no held path holds', function () use ($files, $names, $makeTrees): void {
    $trees = $makeTrees();

    $units = TreeUnits::of(
        $trees,
        $files(
            'src/Money.php',
            'src/Kernel/Boot.php',
            'src/Kernel/Load.php',
            'src/Generated/Proxy.php',
            'src/README.md',
            'lib/Price.php',
            'tests/MoneyTest.php',
            'composer.json',
        ),
        Units::of(
            Unit::held(Path::of('src/Kernel'), Group::named('holds:src/Kernel')),
            Unit::held(Path::of('src/Generated/Held.php'), Group::named('holds:src/Generated/Held.php')),
        ),
    );

    expect($names($units))->toBe(['src/Kernel (held)', 'src/Money.php', 'lib/Price.php']);
});

it('makes no unit where no tree is', function () use ($files, $names): void {
    expect($names(TreeUnits::of(Trees::none(), $files('src/Money.php'), Units::none())))->toBe([]);
});
