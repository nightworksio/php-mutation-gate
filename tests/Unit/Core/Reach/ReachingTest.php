<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Coverage\Judges;
use NightWorksIO\MutationGate\Core\Coverage\NoMap;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Reach\Layout;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Reach\Reaching;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Reach\Sources;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\Unit;

$root = Package::at(Path::root());
$core = Package::at(Path::of('packages/core'));
$money = Package::at(Path::of('packages/money'))->dependingOn(Path::of('packages/core'));

$reaching = static fn(): Reaching => new Reaching(
    Layout::standard()
        ->runBy(Glob::of('.github/workflows/gate.yml'))
        ->testedIn(Paths::of(Path::of('tests'), Path::of('app-modules/billing/tests')))
        ->withModule(Path::of('app-modules/billing')),
    Trees::of(
        Tree::at(Path::of('src'), Undeclared::floor(), $root),
        Tree::at(Path::of('app-modules/billing/src'), Undeclared::floor(), $root),
        Tree::at(Path::of('app-modules/billing/lib'), Undeclared::floor(), $root),
        Tree::at(Path::of('packages/core/src'), Undeclared::floor(), $core),
        Tree::at(Path::of('packages/money/src'), Undeclared::floor(), $money),
    ),
);

$judges = static fn(): Judges => Judges::none()
    ->judging(Path::of('src/Money.php'), Paths::of(Path::of('tests/Unit/MoneyTest.php'), Path::of('tests/Unit/ClockTest.php')))
    ->judging(Path::of('src/Ledger.php'), Paths::of(Path::of('tests/Unit/MoneyTest.php')))
    ->judging(Path::of('src/Clock.php'), Paths::of(Path::of('tests/Unit/ClockTest.php')));

$said = static fn(Reach $reach): array => array_map(
    static fn(Reason $reason): string => $reason->text(),
    iterator_to_array($reach->reasons(), preserve_keys: true),
);

$fake = "<?php\n\nnamespace Tests\\Fakes;\n\nfinal class ClockFake {}\n";

$clockTest = "<?php\n\nuse Tests\\Fakes\\ClockFake;\n\nit('ticks', fn () => new ClockFake());\n";

it('reaches everything where git cannot tell what changed', function () use ($reaching, $judges, $said): void {
    $reach = $reaching()->of(CannotTell::because('git gave no answer.'), $judges(), Sources::none());

    expect($reach->isEverywhere())->toBeTrue()
        ->and($said($reach))->toBe(['git gave no answer. So every unit is reached.']);
});

it('reaches nothing where nothing changed', function () use ($reaching, $judges, $said): void {
    $reach = $reaching()->of(Changes::none(), $judges(), Sources::none());

    expect($reach->reaches(Unit::file(Path::of('src/Money.php'))))->toBeFalse()
        ->and($said($reach))->toBe([]);
});

it('reaches everything where a root file decides how the gate runs, even where it moved or went', function (Change $change, string $deciding) use ($reaching, $judges, $said): void {
    $reach = $reaching()->of(Changes::of($change), $judges(), Sources::none());

    expect($reach->isEverywhere())->toBeTrue()
        ->and($said($reach))->toBe([sprintf('`%s` decides how the gate runs, so every unit is reached.', $deciding)]);
})->with([
    'changed' => [fn(): Change => Change::modified(Path::of('phpunit.xml'), Lines::of(Line::of(3))), 'phpunit.xml'],
    'renamed away' => [fn(): Change => Change::renamed(Path::of('phpunit.xml'), Path::of('phpunit.old'), Lines::none()), 'phpunit.xml'],
    'renamed to' => [fn(): Change => Change::renamed(Path::of('phpunit.old'), Path::of('phpunit.xml'), Lines::none()), 'phpunit.xml'],
    'deleted' => [fn(): Change => Change::deleted(Path::of('tests/Pest.php')), 'tests/Pest.php'],
]);

it('reaches a package whole, and every package that depends on it, where a file of its decides how the gate runs', function () use ($reaching, $judges, $said, $core, $money, $root): void {
    $reach = $reaching()->of(Changes::of(Change::modified(Path::of('packages/core/composer.json'), Lines::of(Line::of(2)))), $judges(), Sources::none());

    expect($reach->isEverywhere())->toBeFalse()
        ->and($reach->reachesPackage($core))->toBeTrue()
        ->and($reach->reachesPackage($money))->toBeTrue()
        ->and($reach->reachesPackage($root))->toBeFalse()
        ->and($reach->reaches(Unit::file(Path::of('packages/money/src/Money.php'))))->toBeTrue()
        ->and($said($reach))->toBe([
            '`packages/core/composer.json` decides how the gate runs in packages/core, so every unit of packages/core, packages/money is reached.',
        ]);
});

it('reaches nothing where a CI definition moved only its action pins', function () use ($reaching, $judges, $said): void {
    $workflow = static fn(string $pin): Contents => Contents::of(sprintf("steps:\n  - uses: actions/checkout@%s # v4\n", str_repeat($pin, 40)));
    $sources = Sources::none()
        ->withBefore(Path::of('.github/workflows/gate.yml'), $workflow('a'))
        ->withNow(Path::of('.github/workflows/gate.yml'), $workflow('b'));

    $reach = $reaching()->of(Changes::of(Change::modified(Path::of('.github/workflows/gate.yml'), Lines::of(Line::of(2)))), $judges(), $sources);

    expect($reach->isEverywhere())->toBeFalse()
        ->and($reach->reaches(Unit::file(Path::of('src/Money.php'))))->toBeFalse()
        ->and($said($reach))->toBe(['`.github/workflows/gate.yml` moved only the commits its actions are pinned at, so it reaches nothing.']);
});

it('reaches everything where a CI definition changed more than its pins, or cannot be compared', function (Sources $sources) use ($reaching, $judges, $said): void {
    $reach = $reaching()->of(Changes::of(Change::modified(Path::of('.github/workflows/gate.yml'), Lines::of(Line::of(2)))), $judges(), $sources);

    expect($reach->isEverywhere())->toBeTrue()
        ->and($said($reach))->toBe(['`.github/workflows/gate.yml` decides how the gate runs, so every unit is reached.']);
})->with([
    'more than pins' => fn(): Sources => Sources::none()
        ->withBefore(Path::of('.github/workflows/gate.yml'), Contents::of("steps:\n  - run: composer test\n"))
        ->withNow(Path::of('.github/workflows/gate.yml'), Contents::of("steps:\n  - run: composer test:all\n")),
    'no base' => fn(): Sources => Sources::none()
        ->withNow(Path::of('.github/workflows/gate.yml'), Contents::of("steps:\n  - run: composer test\n")),
    'nothing on disk' => fn(): Sources => Sources::none()
        ->withBefore(Path::of('.github/workflows/gate.yml'), Contents::of("steps:\n  - run: composer test\n")),
]);

it('reaches the unit of a changed source file, and knows the lines it changed', function () use ($reaching, $judges, $said): void {
    $reach = $reaching()->of(Changes::of(
        Change::modified(Path::of('src/Money.php'), Lines::of(Line::of(3), Line::of(4))),
        Change::added(Path::of('packages/money/src/Rate.php'), Lines::of(Line::of(1))),
        Change::renamed(Path::of('src/Old.php'), Path::of('src/Clock.php'), Lines::none()),
    ), $judges(), Sources::none());

    expect($reach->reaches(Unit::file(Path::of('src/Money.php'))))->toBeTrue()
        ->and($reach->reaches(Unit::file(Path::of('packages/money/src/Rate.php'))))->toBeTrue()
        ->and($reach->reaches(Unit::file(Path::of('src/Clock.php'))))->toBeTrue()
        ->and($reach->reaches(Unit::file(Path::of('src/Ledger.php'))))->toBeFalse()
        ->and($reach->changedLines(Path::of('src/Money.php')))->toEqual(Lines::of(Line::of(3), Line::of(4)))
        ->and($reach->changedLines(Path::of('src/Clock.php')))->toEqual(Lines::none())
        ->and($said($reach))->toBe([
            '`src/Money.php` changed, so its unit is reached.',
            '`packages/money/src/Rate.php` changed, so its unit is reached.',
            '`src/Clock.php` changed, so its unit is reached.',
        ]);
});

it('reaches no unit of a deleted source file, which leaves its tree', function () use ($reaching, $judges, $said): void {
    $reach = $reaching()->of(Changes::of(Change::deleted(Path::of('src/Money.php'))), $judges(), Sources::none());

    expect($reach->reaches(Unit::file(Path::of('src/Money.php'))))->toBeFalse()
        ->and($said($reach))->toBe(['`src/Money.php` was deleted, so it leaves its tree with nothing to mutate.']);
});

it('reaches every file a changed test runs, by the coverage map', function () use ($reaching, $judges, $said): void {
    $reach = $reaching()->of(Changes::of(Change::modified(Path::of('tests/Unit/MoneyTest.php'), Lines::of(Line::of(9)))), $judges(), Sources::none());

    expect($reach->reaches(Unit::file(Path::of('src/Money.php'))))->toBeTrue()
        ->and($reach->reaches(Unit::file(Path::of('src/Ledger.php'))))->toBeTrue()
        ->and($reach->reaches(Unit::file(Path::of('src/Clock.php'))))->toBeFalse()
        ->and($reach->changedLines(Path::of('tests/Unit/MoneyTest.php')))->toEqual(Lines::none())
        ->and($said($reach))->toBe(['`tests/Unit/MoneyTest.php` changed, so the 2 files its tests run are reached.']);
});

it('reaches every tree of the module a changed test is in', function () use ($reaching, $judges, $said): void {
    $reach = $reaching()->of(Changes::of(Change::added(Path::of('app-modules/billing/tests/InvoiceTest.php'), Lines::of(Line::of(1)))), $judges(), Sources::none());

    expect($reach->reaches(Unit::file(Path::of('app-modules/billing/src/Invoice.php'))))->toBeTrue()
        ->and($reach->reaches(Unit::file(Path::of('app-modules/billing/lib/Tax.php'))))->toBeTrue()
        ->and($reach->reaches(Unit::file(Path::of('src/Money.php'))))->toBeFalse()
        ->and($said($reach))->toBe([
            '`app-modules/billing/tests/InvoiceTest.php` changed, so the 0 files its tests run are reached.',
            '`app-modules/billing/tests/InvoiceTest.php` is a test of the module app-modules/billing, so every tree of it is reached.',
        ]);
});

it('reaches every unit of its package where a test was deleted, or no map says what it runs', function (Change $change, Judges|NoMap $coverage, string $said) use ($reaching, $root, $money): void {
    $reach = $reaching()->of(Changes::of($change), $coverage, Sources::none());

    expect($reach->isEverywhere())->toBeFalse()
        ->and(array_map(static fn(Reason $reason): string => $reason->text(), iterator_to_array($reach->reasons(), preserve_keys: true)))->toBe([$said])
        ->and($reach->reachesPackage(str_starts_with($change->path()->value(), 'packages/money') ? $money : $root))->toBeTrue();
})->with([
    'deleted' => [
        fn(): Change => Change::deleted(Path::of('tests/Unit/MoneyTest.php')),
        fn(): Judges => Judges::none(),
        '`tests/Unit/MoneyTest.php` was deleted, so every unit of the project is reached.',
    ],
    'deleted in a package' => [
        fn(): Change => Change::deleted(Path::of('packages/money/tests/MoneyTest.php')),
        fn(): Judges => Judges::none(),
        '`packages/money/tests/MoneyTest.php` was deleted, so every unit of packages/money is reached.',
    ],
    'no map' => [
        fn(): Change => Change::modified(Path::of('tests/Unit/MoneyTest.php'), Lines::of(Line::of(9))),
        fn(): NoMap => NoMap::toRead(),
        'No coverage map says what `tests/Unit/MoneyTest.php` runs, so every unit of the project is reached.',
    ],
]);

it('reaches what the tests that use changed test support run', function () use ($reaching, $judges, $said, $fake, $clockTest): void {
    $sources = Sources::none()
        ->withNow(Path::of('tests/Fakes/ClockFake.php'), Contents::of($fake))
        ->withNow(Path::of('tests/Unit/ClockTest.php'), Contents::of($clockTest))
        ->withNow(Path::of('tests/Unit/MoneyTest.php'), Contents::of("<?php\n\nit('adds', fn () => 1);\n"));

    $reach = $reaching()->of(Changes::of(Change::modified(Path::of('tests/Fakes/ClockFake.php'), Lines::of(Line::of(5)))), $judges(), $sources);

    expect($reach->reaches(Unit::file(Path::of('src/Money.php'))))->toBeTrue()
        ->and($reach->reaches(Unit::file(Path::of('src/Clock.php'))))->toBeTrue()
        ->and($reach->reaches(Unit::file(Path::of('src/Ledger.php'))))->toBeFalse()
        ->and($said($reach))->toBe(['`tests/Fakes/ClockFake.php` is test support 1 tests use, so the 2 files they run are reached.']);
});

it('finds the users of support that is gone by what it declared at the base', function () use ($reaching, $judges, $said, $fake, $clockTest): void {
    $sources = Sources::none()
        ->withBefore(Path::of('tests/Fakes/ClockFake.php'), Contents::of($fake))
        ->withNow(Path::of('tests/Unit/ClockTest.php'), Contents::of($clockTest));

    $reach = $reaching()->of(Changes::of(Change::deleted(Path::of('tests/Fakes/ClockFake.php'))), $judges(), $sources);

    expect($reach->reaches(Unit::file(Path::of('src/Clock.php'))))->toBeTrue()
        ->and($said($reach))->toBe(['`tests/Fakes/ClockFake.php` is test support 1 tests use, so the 2 files they run are reached.']);
});

it('finds the users of support by what it declared at the base under its old name', function () use ($reaching, $judges, $fake, $clockTest): void {
    $sources = Sources::none()
        ->withBefore(Path::of('tests/Fakes/OldClock.php'), Contents::of($fake))
        ->withNow(Path::of('tests/Fakes/ClockFake.php'), Contents::of("<?php\n\nnamespace Tests\\Fakes;\n\nfinal class Clock {}\n"))
        ->withNow(Path::of('tests/Unit/ClockTest.php'), Contents::of($clockTest));

    $reach = $reaching()->of(
        Changes::of(Change::renamed(Path::of('tests/Fakes/OldClock.php'), Path::of('tests/Fakes/ClockFake.php'), Lines::of(Line::of(5)))),
        $judges(),
        $sources,
    );

    expect($reach->reaches(Unit::file(Path::of('src/Clock.php'))))->toBeTrue();
});

it('reaches every unit of its package where changed support cannot be followed', function (Sources $sources, Judges|NoMap $coverage, string $said) use ($reaching): void {
    $reach = $reaching()->of(Changes::of(Change::modified(Path::of('tests/Fakes/ClockFake.php'), Lines::of(Line::of(5)))), $coverage, $sources);

    expect($reach->isEverywhere())->toBeFalse()
        ->and($reach->reachesPackage(Package::at(Path::root())))->toBeTrue()
        ->and(array_map(static fn(Reason $reason): string => $reason->text(), iterator_to_array($reach->reasons(), preserve_keys: true)))->toBe([$said]);
})->with([
    'it runs code' => [
        fn(): Sources => Sources::none()->withNow(Path::of('tests/Fakes/ClockFake.php'), Contents::of("<?php\n\nboot();\n")),
        fn(): Judges => Judges::none(),
        '`tests/Fakes/ClockFake.php` runs code when it is loaded, so every unit of the project is reached.',
    ],
    'no map' => [
        fn(): Sources => Sources::none(),
        fn(): NoMap => NoMap::toRead(),
        'No coverage map says what `tests/Fakes/ClockFake.php` runs, so every unit of the project is reached.',
    ],
]);

it('reaches nothing with anything else', function () use ($reaching, $judges, $said): void {
    $reach = $reaching()->of(Changes::of(
        Change::modified(Path::of('README.md'), Lines::of(Line::of(1))),
        Change::modified(Path::of('src/views/money.json'), Lines::of(Line::of(1))),
    ), $judges(), Sources::none());

    expect($reach->reaches(Unit::file(Path::of('src/Money.php'))))->toBeFalse()
        ->and($said($reach))->toBe(['`README.md` reaches nothing by itself.', '`src/views/money.json` reaches nothing by itself.']);
});
