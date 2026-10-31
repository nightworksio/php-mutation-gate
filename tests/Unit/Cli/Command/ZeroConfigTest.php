<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Command\ZeroConfig;
use NightWorksIO\MutationGate\Cli\FirstParty;
use NightWorksIO\MutationGate\Core\Config\DeclaredTree;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\ThisPackage;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Port\TreeSource;
use NightWorksIO\MutationGate\Tests\Fakes\TreeSourceFake;
use NightWorksIO\MutationGate\Tests\Support\Configs;

it('declares each tree it found at the floor every tree starts at, keeping an exempt one exempt', function (): void {
    $exempt = Exempt::because('generated');
    $trees = Trees::of(
        Tree::at(Path::of('src'), Floor::of(80), Package::at(Path::root())),
        Tree::at(Path::of('generated'), $exempt, Package::at(Path::root())),
    );
    $extensions = new FirstParty()->extend(Extensions::of(Origin::of(ThisPackage::COMPOSER)))
        ->withTreeSource(Name::of('phpunit'), static fn(): TreeSource => new TreeSourceFake($trees));
    $found = ZeroConfig::found($extensions, Configs::settings(['runner' => 'pest']));

    expect($found instanceof ZeroConfig ? $found->declared() : $found)->toEqual(Listed::of(
        DeclaredTree::of(Path::of('src'), Undeclared::floor(), Listed::of()),
        DeclaredTree::of(Path::of('generated'), $exempt, Listed::of()),
    ));
});
