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
use NightWorksIO\MutationGate\Tests\Support\Growth;

$makeMoney = static fn(): Package => Package::at(Path::of('packages/money'));

$nothing = static fn(): Reach => Reach::nothing(Packages::of(Trees::of(
    Tree::at(Path::of('src'), Undeclared::floor(), Package::at(Path::root())),
    Tree::at(Path::of('packages/money/src'), Undeclared::floor(), $makeMoney()),
)));

$said = static fn(Reach $reach): array => array_map(
    static fn(Reason $reason): string => $reason->text(),
    iterator_to_array($reach->reasons(), preserve_keys: true),
);

it('reaches nothing to begin with', function () use ($nothing, $said, $makeMoney): void {
    $money = $makeMoney();

    $reach = $nothing();

    expect($reach->reaches(Unit::file(Path::of('src/Money.php'))))->toBeFalse()
        ->and($reach->reachesPackage(Package::at(Path::root())))->toBeFalse()
        ->and($reach->reachesPackage($money))->toBeFalse()
        ->and($reach->isEverywhere())->toBeFalse()
        ->and($reach->changedLines(Path::of('src/Money.php')))->toEqual(Lines::none())
        ->and($said($reach))->toBe([]);
});

it('reaches every unit of every package everywhere', function () use ($nothing, $said, $makeMoney): void {
    $money = $makeMoney();

    $reach = $nothing()->everywhere(Reason::that('everything'));

    expect($reach->reaches(Unit::file(Path::of('src/Money.php'))))->toBeTrue()
        ->and($reach->reaches(Unit::held(Path::of('packages/money/src'), Group::named('holds:packages/money/src'))))->toBeTrue()
        ->and($reach->reachesPackage($money))->toBeTrue()
        ->and($reach->isEverywhere())->toBeTrue()
        ->and($said($reach))->toBe(['everything']);
});

it('reaches every unit of the packages it reaches whole, and nothing else', function () use ($nothing, $said, $makeMoney): void {
    $money = $makeMoney();

    $reach = $nothing()->wholly(Paths::of(Path::of('packages/money')), Reason::that('money'));

    expect($reach->reaches(Unit::file(Path::of('packages/money/src/Money.php'))))->toBeTrue()
        ->and($reach->reaches(Unit::file(Path::of('src/Kernel.php'))))->toBeFalse()
        ->and($reach->reachesPackage($money))->toBeTrue()
        ->and($reach->reachesPackage(Package::at(Path::root())))->toBeFalse()
        ->and($reach->isEverywhere())->toBeFalse()
        ->and($said($reach))->toBe(['money']);
});

it('reaches the unit of each file it reaches, and a held unit around it', function () use ($nothing, $said, $makeMoney): void {
    $money = $makeMoney();

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

it('reaches every unit of each tree it reaches', function () use ($nothing, $said, $makeMoney): void {
    $money = $makeMoney();

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

it('keeps what it reached as it reaches more', function () use ($nothing, $said, $makeMoney): void {
    $money = $makeMoney();

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

it('says whether reached files and trees reach each unit in time linear in their number', function () use ($nothing): void {
    $reached = static function (int $size) use ($nothing): Closure {
        $files = Paths::of(...array_map(static fn(int $at): Path => Path::of(sprintf('src/D%d/F.php', $at)), range(1, $size)));
        $trees = Paths::of(...array_map(static fn(int $at): Path => Path::of(sprintf('packages/money/src/T%d', $at)), range(1, $size)));
        $units = array_map(static fn(int $at): Unit => Unit::file(Path::of(sprintf('src/D%d', $at))), range(1, $size * 2));

        return static function () use ($nothing, $files, $trees, $units): int {
            $reach = $nothing()->files($files, Reason::that('files'))->trees($trees, Reason::that('trees'));
            $reached = 0;

            foreach ($units as $unit) {
                $reached += $reach->reaches($unit) ? 1 : 0;
            }

            return $reached;
        };
    };

    expect($reached(10)())->toBe(10)
        ->and(Growth::of(500, $reached))->toBeLessThan(Growth::LINEAR);
});

it('says whether the change added or modified a line of a unit, or of a file a held unit holds', function () use ($nothing): void {
    $reach = $nothing()
        ->withLines(Path::of('src/Money.php'), Lines::of(Line::of(3)))
        ->withLines(Path::of('src/Clock/Tick.php'), Lines::of(Line::of(9)))
        ->withLines(Path::of('src/Removed.php'), Lines::none());

    expect($reach->changesLinesOf(Unit::file(Path::of('src/Money.php'))))->toBeTrue()
        ->and($reach->changesLinesOf(Unit::held(Path::of('src/Clock'), Group::named('holds:src/Clock'))))->toBeTrue()
        ->and($reach->changesLinesOf(Unit::file(Path::of('src/Removed.php'))))->toBeFalse()
        ->and($reach->changesLinesOf(Unit::file(Path::of('src/Other.php'))))->toBeFalse();
});
