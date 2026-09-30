<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\Unit;

$money = Package::at(Path::of('packages/money'));

$nothing = static fn(): Reach => Reach::nothing(Packages::of(Trees::of(
    Tree::at(Path::of('src'), Undeclared::floor(), Package::at(Path::root())),
    Tree::at(Path::of('packages/money/src'), Undeclared::floor(), $money),
)));

$said = static fn(Reach $reach): array => array_map(
    static fn(Reason $reason): string => $reason->text(),
    iterator_to_array($reach->reasons(), preserve_keys: true),
);

it('reaches nothing to begin with', function () use ($nothing, $said, $money): void {
    $reach = $nothing();

    expect($reach->reaches(Unit::file(Path::of('src/Money.php'))))->toBeFalse()
        ->and($reach->reachesPackage(Package::at(Path::root())))->toBeFalse()
        ->and($reach->reachesPackage($money))->toBeFalse()
        ->and($reach->isEverywhere())->toBeFalse()
        ->and($reach->changedLines(Path::of('src/Money.php')))->toEqual(Lines::none())
        ->and($said($reach))->toBe([]);
});

it('reaches every unit of every package everywhere', function () use ($nothing, $said, $money): void {
    $reach = $nothing()->everywhere(Reason::that('everything'));

    expect($reach->reaches(Unit::file(Path::of('src/Money.php'))))->toBeTrue()
        ->and($reach->reaches(Unit::held(Path::of('packages/money/src'), Group::named('holds:packages/money/src'))))->toBeTrue()
        ->and($reach->reachesPackage($money))->toBeTrue()
        ->and($reach->isEverywhere())->toBeTrue()
        ->and($said($reach))->toBe(['everything']);
});

it('reaches every unit of the packages it reaches whole, and nothing else', function () use ($nothing, $said, $money): void {
    $reach = $nothing()->wholly(Paths::of(Path::of('packages/money')), Reason::that('money'));

    expect($reach->reaches(Unit::file(Path::of('packages/money/src/Money.php'))))->toBeTrue()
        ->and($reach->reaches(Unit::file(Path::of('src/Kernel.php'))))->toBeFalse()
        ->and($reach->reachesPackage($money))->toBeTrue()
        ->and($reach->reachesPackage(Package::at(Path::root())))->toBeFalse()
        ->and($reach->isEverywhere())->toBeFalse()
        ->and($said($reach))->toBe(['money']);
});

it('reaches the unit of each file it reaches, and a held unit around it', function () use ($nothing, $said, $money): void {
    $reach = $nothing()
        ->files(Paths::of(Path::of('src/Money.php')), Reason::that('money'))
        ->files(Paths::of(Path::of('src/Http/Kernel.php'), Path::of('src/Clock.php')), Reason::that('kernel'));

    expect($reach->reaches(Unit::file(Path::of('src/Money.php'))))->toBeTrue()
        ->and($reach->reaches(Unit::file(Path::of('src/Clock.php'))))->toBeTrue()
        ->and($reach->reaches(Unit::held(Path::of('src/Http'), Group::named('holds:src/Http'))))->toBeTrue()
        ->and($reach->reaches(Unit::file(Path::of('src/Ledger.php'))))->toBeFalse()
        ->and($reach->reachesPackage(Package::at(Path::root())))->toBeTrue()
        ->and($reach->reachesPackage($money))->toBeFalse()
        ->and($said($reach))->toBe(['money', 'kernel']);
});

it('reaches every unit of each tree it reaches', function () use ($nothing, $said, $money): void {
    $reach = $nothing()->trees(Paths::of(Path::of('packages/money/src'), Path::of('packages/money/lib')), Reason::that('module'));

    expect($reach->reaches(Unit::file(Path::of('packages/money/src/Money.php'))))->toBeTrue()
        ->and($reach->reaches(Unit::file(Path::of('packages/money/lib/Rate.php'))))->toBeTrue()
        ->and($reach->reaches(Unit::file(Path::of('packages/money/tests/MoneyTest.php'))))->toBeFalse()
        ->and($reach->reachesPackage($money))->toBeTrue()
        ->and($reach->reachesPackage(Package::at(Path::root())))->toBeFalse()
        ->and($said($reach))->toBe(['module']);
});

it('records a decision that reaches nothing', function () use ($nothing, $said): void {
    $reach = $nothing()->because(Reason::that('nothing'));

    expect($reach->reaches(Unit::file(Path::of('src/Money.php'))))->toBeFalse()
        ->and($reach->reachesPackage(Package::at(Path::root())))->toBeFalse()
        ->and($said($reach))->toBe(['nothing']);
});

it('knows the lines a change added or modified in each source file', function () use ($nothing, $said): void {
    $reach = $nothing()
        ->because(Reason::that('first'))
        ->withLines(Path::of('src/Money.php'), Lines::of(Line::of(3), Line::of(4)))
        ->withLines(Path::of('src/Clock.php'), Lines::of(Line::of(9)));

    expect($reach->changedLines(Path::of('src/Money.php')))->toEqual(Lines::of(Line::of(3), Line::of(4)))
        ->and($reach->changedLines(Path::of('src/Clock.php')))->toEqual(Lines::of(Line::of(9)))
        ->and($reach->changedLines(Path::of('src/Ledger.php')))->toEqual(Lines::none())
        ->and($said($reach))->toBe(['first']);
});

it('keeps what it reached as it reaches more', function () use ($nothing, $said, $money): void {
    $reach = $nothing()
        ->withLines(Path::of('src/Money.php'), Lines::of(Line::of(3)))
        ->files(Paths::of(Path::of('src/Money.php')), Reason::that('file'))
        ->trees(Paths::of(Path::of('app-modules/billing/src')), Reason::that('tree'))
        ->wholly(Paths::of(Path::of('packages/money')), Reason::that('package'))
        ->because(Reason::that('nothing'))
        ->everywhere(Reason::that('everything'))
        ->withLines(Path::of('src/Clock.php'), Lines::of(Line::of(9)));
    $parts = $nothing()
        ->files(Paths::of(Path::of('src/Money.php')), Reason::that('file'))
        ->trees(Paths::of(Path::of('app-modules/billing/src')), Reason::that('tree'))
        ->wholly(Paths::of(Path::of('packages/money')), Reason::that('package'))
        ->because(Reason::that('nothing'))
        ->withLines(Path::of('src/Money.php'), Lines::of(Line::of(3)));

    expect($reach->isEverywhere())->toBeTrue()
        ->and($reach->changedLines(Path::of('src/Money.php')))->toEqual(Lines::of(Line::of(3)))
        ->and($said($reach))->toBe(['file', 'tree', 'package', 'nothing', 'everything'])
        ->and($parts->reaches(Unit::file(Path::of('src/Money.php'))))->toBeTrue()
        ->and($parts->reaches(Unit::file(Path::of('app-modules/billing/src/Invoice.php'))))->toBeTrue()
        ->and($parts->reachesPackage($money))->toBeTrue()
        ->and($parts->isEverywhere())->toBeFalse()
        ->and($said($parts))->toBe(['file', 'tree', 'package', 'nothing']);
});

it('leaves the reach it came from as it was', function () use ($nothing, $said): void {
    $reach = $nothing();
    $reach->everywhere(Reason::that('everything'));
    $reach->files(Paths::of(Path::of('src/Money.php')), Reason::that('file'));
    $reach->withLines(Path::of('src/Money.php'), Lines::of(Line::of(3)));

    expect($reach->isEverywhere())->toBeFalse()
        ->and($reach->reaches(Unit::file(Path::of('src/Money.php'))))->toBeFalse()
        ->and($reach->changedLines(Path::of('src/Money.php')))->toEqual(Lines::none())
        ->and($said($reach))->toBe([]);
});
