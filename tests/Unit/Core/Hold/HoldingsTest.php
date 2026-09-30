<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Fingerprint;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Hold\Holder;
use NightWorksIO\MutationGate\Core\Hold\Holding;
use NightWorksIO\MutationGate\Core\Hold\Holdings;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Groups;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;

$trees = static fn(): Trees => Trees::of(Tree::at(Path::of('src'), Undeclared::floor(), Package::at(Path::root())));

$files = static fn(): Fingerprints => Fingerprints::of(
    Fingerprint::of(Path::of('src/Kernel.php'), Digest::of('a1')),
    Fingerprint::of(Path::of('src/Http/Controller.php'), Digest::of('b2')),
    Fingerprint::of(Path::of('app/Kernel.php'), Digest::of('c3')),
);

it('holds nothing to begin with', function () use ($trees, $files): void {
    expect(Holdings::none()->units($trees(), $files()))->toEqual(Units::none())
        ->and(Holdings::none()->anyByAttribute())->toBeFalse();
});

it('reads a held path from each group whose name says it holds one, and no other group', function () use ($trees, $files): void {
    $holdings = Holdings::inGroups(Groups::of(
        Group::named('holds:src/Kernel.php'),
        Group::named('slow'),
        Group::named('holds:src/Http'),
    ));

    expect($holdings->units($trees(), $files()))->toEqual(Units::of(
        Unit::held(Path::of('src/Kernel.php'), Group::named('holds:src/Kernel.php')),
        Unit::held(Path::of('src/Http'), Group::named('holds:src/Http')),
    ))
        ->and($holdings->anyByAttribute())->toBeFalse();
});

it('judges a path #[Holds] declares by a filter that selects each holding class and method', function () use ($trees, $files): void {
    $holdings = Holdings::none()
        ->with(Holding::byAttribute('src/Http', Holder::of('Tests\HttpTest')))
        ->with(Holding::byAttribute('src/Http', Holder::of('Tests\BootTest::testBoots')));

    expect($holdings->units($trees(), $files()))->toEqual(Units::of(
        Unit::held(Path::of('src/Http'), Filter::matching('/^(?:Tests\\\\HttpTest::|Tests\\\\BootTest\\:\\:testBoots\\b)/')),
    ))
        ->and($holdings->anyByAttribute())->toBeTrue();
});

it('holds what groups and attributes hold together, where they hold different paths', function () use ($trees, $files): void {
    $holdings = Holdings::inGroups(Groups::of(Group::named('holds:src/Kernel.php')))
        ->merge(Holdings::none()->with(Holding::byAttribute('src/Http', Holder::of('Tests\HttpTest'))));

    expect($holdings->units($trees(), $files()))->toEqual(Units::of(
        Unit::held(Path::of('src/Kernel.php'), Group::named('holds:src/Kernel.php')),
        Unit::held(Path::of('src/Http'), Filter::matching('/^(?:Tests\\\\HttpTest::)/')),
    ));
});

it('holds a tree whole, whether or not a file is in it', function () use ($files): void {
    $trees = Trees::of(Tree::at(Path::of('lib'), Undeclared::floor(), Package::at(Path::root())));

    expect(Holdings::inGroups(Groups::of(Group::named('holds:lib')))->units($trees, $files()))
        ->toEqual(Units::of(Unit::held(Path::of('lib'), Group::named('holds:lib'))));
});

it('cannot judge a path that is not spelt as the repository spells it, or is not in a tree', function (string $declared) use ($trees, $files): void {
    $holdings = Holdings::inGroups(Groups::of(Group::named(sprintf('holds:%s', $declared))));

    expect($holdings->units($trees(), $files()))->toEqual(CannotJudge::because(sprintf(
        "holds:%1\$s names %1\$s, which is not a tree, or a file or directory inside one,\nspelt as the repository spells it. A misspelt path would otherwise be mutated\nagainst the whole suite, correct and slow, with no sign the declaration was never read.",
        $declared,
    )));
})->with(['src/Kernel.php/', './src/Kernel.php', 'src/Kenrel.php', 'app/Kernel.php', '']);

it('names the attribute that declares a path it cannot judge', function () use ($trees, $files): void {
    $holdings = Holdings::none()->with(Holding::byAttribute('src/Kenrel.php', Holder::of('Tests\KernelTest')));

    expect($holdings->units($trees(), $files()))->toEqual(CannotJudge::because(
        "#[Holds('src/Kenrel.php')] on Tests\\KernelTest names src/Kenrel.php, which is not a tree, or a file or directory inside one,\nspelt as the repository spells it. A misspelt path would otherwise be mutated\nagainst the whole suite, correct and slow, with no sign the declaration was never read.",
    ));
});

it('cannot judge a path held both by a group and by #[Holds]', function () use ($trees, $files): void {
    $holdings = Holdings::inGroups(Groups::of(Group::named('holds:src/Kernel.php')))
        ->merge(Holdings::none()->with(Holding::byAttribute('src/Kernel.php', Holder::of('Tests\KernelTest'))));

    expect($holdings->units($trees(), $files()))->toEqual(CannotJudge::because(
        "src/Kernel.php is held both by the group holds:src/Kernel.php and by #[Holds].\nA held path is judged by one set of tests, so declare it one way.",
    ));
});

it('cannot judge a held path inside another', function () use ($trees, $files): void {
    $holdings = Holdings::inGroups(Groups::of(Group::named('holds:src'), Group::named('holds:src/Kernel.php')));

    expect($holdings->units($trees(), $files()))->toEqual(CannotJudge::because(
        "src is held, and so is src/Kernel.php inside it, so a mutant there would be judged twice.\nHold one or the other.",
    ));
});

it('leaves the holdings it came from as they were', function () use ($trees, $files): void {
    $holdings = Holdings::none();
    $holdings->with(Holding::byAttribute('src/Http', Holder::of('Tests\HttpTest')));
    $holdings->merge(Holdings::inGroups(Groups::of(Group::named('holds:src/Kernel.php'))));

    expect($holdings->units($trees(), $files()))->toEqual(Units::none());
});
