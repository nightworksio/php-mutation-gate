<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Reach\AffectedTests;
use NightWorksIO\MutationGate\Tests\Support\Affected;

$modified = static fn(string $path): Change => Change::modified(Path::of($path), Lines::none());

/** @return list<string> each test file listed */
$files = static fn(AffectedTests $affected): array => array_column(Affected::listed($affected), 0);

it('lists for a changed source file the tests that run its lines, and those that read what it declares', function () use (
    $modified,
): void {
    $affected = Affected::to(Changes::of($modified('src/Money.php')));

    expect(Affected::listed($affected))->toBe([
        [
            'tests/PriceTest.php',
            ['PriceTest::totals'],
            ['`src/Money.php` declares constant App\Money::RATE, read on line 7 of `src/Price.php`, which these tests run.'],
        ],
        ['tests/RateTest.php', ['RateTest::rates'], ['`src/Money.php` declares constant App\Money::RATE, which these tests read.']],
        [
            'tests/MoneyTest.php',
            ['MoneyTest::adds', 'MoneyTest::adds nothing'],
            ['`src/Money.php` changed, and these tests run 1 of its lines.'],
        ],
    ])->and($affected->isEvery())->toBeFalse()
        ->and([...$affected->unreached()])->toBe([]);
});

it('lists a changed test whole', function () use ($modified): void {
    expect(Affected::listed(Affected::to(Changes::of($modified('tests/MoneyTest.php')))))->toBe([
        ['tests/MoneyTest.php', ['MoneyTest::adds', 'MoneyTest::adds nothing'], ['`tests/MoneyTest.php` changed.']],
    ]);
});

it('lists no test for a deleted test, and says so', function (): void {
    $affected = Affected::to(Changes::of(Change::deleted(Path::of('tests/RateTest.php'))));

    expect(Affected::listed($affected))->toBe([])
        ->and(Affected::texts($affected->nothingBecause()))
        ->toBe(['`tests/RateTest.php` was deleted, so there is nothing of it to run.']);
});

it('lists the tests that use changed support', function () use ($modified): void {
    expect(Affected::listed(Affected::to(Changes::of($modified('tests/Support/Builds.php')))))->toBe([
        ['tests/PriceTest.php', ['PriceTest::totals'], ['`tests/Support/Builds.php` is test support these tests use.']],
    ]);
});

it('lists every test, each with all its tests, for a file that decides how the gate runs', function () use (
    $modified,
    $files,
): void {
    $affected = Affected::to(Changes::of($modified('composer.json'), $modified('tests/RateTest.php')));

    expect($affected->isEvery())->toBeTrue()
        ->and($files($affected))->toBe(['tests/MoneyTest.php', 'tests/PriceTest.php', 'tests/RateTest.php'])
        ->and(Affected::listed($affected)[2])->toBe([
            'tests/RateTest.php',
            ['RateTest::rates'],
            ['`tests/RateTest.php` changed.', '`composer.json` decides how the gate runs, so every test is listed.'],
        ])
        ->and(Affected::texts($affected->everyBecause()))
        ->toBe(['`composer.json` decides how the gate runs, so every test is listed.']);
});

it('lists no test for the gate\'s config where its change moved no setting that affects results', function () use ($modified): void {
    $affected = Affected::to(Changes::of($modified('mutation-gate.json')), Affected::FILES, Affected::FILES, [], 'mutation-gate.json');

    expect([$affected->listsNone(), Affected::texts($affected->nothingBecause())])->toBe([
        true,
        ['`mutation-gate.json` changed nothing that decides how the gate runs, so it lists no test.'],
    ]);
});

it('lists every test for a CI definition that runs the gate, and none where it changed only its pins or comments', function (): void {
    $before = "steps:\n  - uses: nightworksio/php-mutation-gate@0123456789abcdef0123456789abcdef01234567 # v1\n";
    $pinned = "steps:\n  - uses: nightworksio/php-mutation-gate@89abcdef0123456789abcdef0123456789abcdef # v1\n";
    $moved = "steps:\n  - uses: nightworksio/php-mutation-gate@0123456789abcdef0123456789abcdef01234567 # v1\n    with:\n      shards: 2\n";
    $definition = '.github/workflows/gate.yml';
    $change = Changes::of(Change::modified(Path::of($definition), Lines::none()));

    $commented = sprintf("# The gate.\n\n%s", $before);

    $repinned = Affected::to($change, [...Affected::FILES, $definition => $pinned], [$definition => $before]);
    $explained = Affected::to($change, [...Affected::FILES, $definition => $commented], [$definition => $before]);
    $edited = Affected::to($change, [...Affected::FILES, $definition => $moved], [$definition => $before]);

    expect([$repinned->listsNone(), Affected::texts($repinned->nothingBecause())])->toBe([
        true,
        ['`.github/workflows/gate.yml` changed only its comments, blank lines or action pins, so it lists no test.'],
    ])->and($explained->listsNone())->toBeTrue()
        ->and($edited->isEvery())->toBeTrue();
});

it('lists every test for a file no rule places, and none for one proofs.ignore matches', function () use ($modified): void {
    $template = Affected::to(Changes::of($modified('resources/invoice.twig')));
    $ignored = Affected::to(Changes::of($modified('templates/mail.twig')));

    expect(Affected::texts($template->everyBecause()))
        ->toBe(['No rule says which tests read `resources/invoice.twig`, so every test is listed.'])
        ->and([$ignored->listsNone(), Affected::texts($ignored->nothingBecause())])
        ->toBe([true, ['`templates/mail.twig` matches proofs.ignore, so it lists no test.']]);
});

it('lists every test for a file in a tree that is not PHP', function () use ($modified): void {
    expect(Affected::to(Changes::of($modified('src/views/total.html')))->isEvery())->toBeTrue();
});

it('lists a test file the runner selects to judge a changed source file whose lines its tests do not run', function () use (
    $modified,
): void {
    expect(Affected::listed(Affected::to(Changes::of($modified('src/Lone.php')))))->toBe([
        ['tests/RateTest.php', ['RateTest::rates'], ['`src/Lone.php` changed, and the runner selects this file to judge it.']],
    ]);
});

it('leaves a changed source file that no test runs or reads unreached, and says so', function () use ($modified): void {
    $now = [...Affected::FILES, 'src/Alone.php' => "<?php\n\nnamespace App;\n\nfinal class Alone {}\n"];
    $affected = Affected::to(Changes::of($modified('src/Alone.php')), $now);

    expect([Affected::listed($affected), [...$affected->unreached()], Affected::texts($affected->nothingBecause())])
        ->toEqual([
            [],
            [Path::of('src/Alone.php')],
            ['`src/Alone.php` changed, and no test runs it or reads what it declares.'],
        ]);
});

it('lists for a new source file the tests that name what it declares, through the support they use too', function (): void {
    $tax = "<?php\n\nnamespace App;\n\nfinal class Tax {}\n";
    $support = "<?php\n\nnamespace Tests\\Support;\n\nuse App\\Tax;\n\nfinal class Builds { public static function tax(): Tax {} }\n";
    $now = [...Affected::FILES, 'src/Tax.php' => $tax, 'tests/Support/Builds.php' => $support];

    expect(Affected::listed(Affected::to(Changes::of(Change::added(Path::of('src/Tax.php'), Lines::none())), $now)))
        ->toBe([['tests/PriceTest.php', ['PriceTest::totals'], ['`src/Tax.php` is new, and these tests name what it declares.']]]);
});

it('leaves a new source file that no test names unreached', function (): void {
    $now = [...Affected::FILES, 'src/Tax.php' => "<?php\n\nnamespace App;\n\nfinal class Tax {}\n"];
    $affected = Affected::to(Changes::of(Change::added(Path::of('src/Tax.php'), Lines::none())), $now);

    expect([[...$affected->unreached()], Affected::texts($affected->nothingBecause())])->toEqual([
        [Path::of('src/Tax.php')],
        ['`src/Tax.php` is new, and no test names what it declares.'],
    ]);
});

it('lists for a deleted source file the tests that ran it and read what it declared', function (): void {
    $now = Affected::FILES;
    unset($now['src/Money.php']);

    expect(array_column(Affected::listed(Affected::to(Changes::of(Change::deleted(Path::of('src/Money.php'))), $now)), 0))
        ->toBe(['tests/PriceTest.php', 'tests/RateTest.php', 'tests/MoneyTest.php']);
});

it('lists for a renamed source file the tests that ran it under its old name', function (): void {
    $now = Affected::FILES;
    $now['src/Cash.php'] = str_replace('class Money', 'class Cash', $now['src/Money.php']);
    unset($now['src/Money.php']);
    $renamed = Change::renamed(Path::of('src/Money.php'), Path::of('src/Cash.php'), Lines::none());

    expect(array_column(Affected::listed(Affected::to(Changes::of($renamed), $now)), 0))->toContain('tests/MoneyTest.php');
});

it('lists every test where code may read a value it declares where no name says so', function () use ($modified): void {
    $now = [...Affected::FILES, 'src/Rates.php' => "<?php\n\nnamespace App;\n\nfinal class Rates\n{\n"
        . "    public function of(string \$class): int { return \$class::RATE; }\n}\n"];

    $affected = Affected::to(Changes::of($modified('src/Money.php')), $now);

    expect(Affected::texts($affected->everyBecause()))->toBe([
        '`src/Money.php` declares constant App\Money::RATE, which code may read where no name says so, so every test is listed.',
    ]);
});

it('lists a file once for every change that reaches it, with each reason', function () use ($modified): void {
    expect(Affected::listed(Affected::to(Changes::of($modified('tests/PriceTest.php'), $modified('tests/Support/Builds.php')))))
        ->toBe([[
            'tests/PriceTest.php',
            ['PriceTest::totals'],
            ['`tests/PriceTest.php` changed.', '`tests/Support/Builds.php` is test support these tests use.'],
        ]]);
});

it('lists a file once for each value of a changed file it reads, with each reason', function () use ($modified): void {
    $money = str_replace('public const RATE = 2;', "public const RATE = 2;\n    public const FEE = 1;", Affected::FILES['src/Money.php']);
    $rate = "<?php\n\nuse App\\Money;\n\nit('rates', fn () => expect(Money::RATE + Money::FEE)->toBe(3));\n";
    $now = [...Affected::FILES, 'src/Money.php' => $money, 'tests/RateTest.php' => $rate];

    expect(Affected::listed(Affected::to(Changes::of($modified('src/Money.php')), $now))[1])->toBe([
        'tests/RateTest.php',
        ['RateTest::rates'],
        [
            '`src/Money.php` declares constant App\\Money::RATE, which these tests read.',
            '`src/Money.php` declares constant App\\Money::FEE, which these tests read.',
        ],
    ]);
});

it('lists the tests that use the test support that reads a value a changed file declares', function () use ($modified): void {
    $rates = "<?php\n\nnamespace Tests\\Support;\n\nuse App\\Money;\n\nfinal class Rates { public static function rate(): int { return Money::RATE; } }\n";
    $price = "<?php\n\nuse Tests\\Support\\Rates;\n\nit('totals', fn () => expect(Rates::rate())->toBe(2));\n";
    $now = [...Affected::FILES, 'tests/Support/Rates.php' => $rates, 'tests/PriceTest.php' => $price];

    expect(Affected::listed(Affected::to(Changes::of($modified('src/Money.php')), $now))[0])->toBe([
        'tests/PriceTest.php',
        ['PriceTest::totals'],
        [
            '`src/Money.php` declares constant App\\Money::RATE, read on line 7 of `src/Price.php`, which these tests run.',
            '`src/Money.php` declares constant App\\Money::RATE, read in `tests/Support/Rates.php`, test support these tests use.',
        ],
    ]);
});

it('lists every test where the support that reads a changed value cannot be read', function () use ($modified): void {
    $rates = "<?php\n\nnamespace Tests\\Support;\n\nuse App\\Money;\n\nfinal class Rates { public static function rate(): int { return Money::RATE; } }\n";

    expect(Affected::texts(Affected::to(Changes::of($modified('src/Money.php')), unread: ['tests/Support/Rates.php' => $rates])->everyBecause()))
        ->toBe(['The tests that use `tests/Support/Rates.php` cannot be told, so every test is listed.']);
});

it('lists every test where the tests that use changed support cannot be told', function (): void {
    expect(Affected::texts(Affected::to(Changes::of(Change::deleted(Path::of('tests/Support/Gone.php'))))->everyBecause()))
        ->toBe(['The tests that use `tests/Support/Gone.php` cannot be told, so every test is listed.']);
});

it('lists every test where a new source file cannot be read', function (): void {
    expect(Affected::texts(Affected::to(Changes::of(Change::added(Path::of('src/Tax.php'), Lines::none())))->everyBecause()))
        ->toBe(['The tests that name what `src/Tax.php` declares cannot be told, so every test is listed.']);
});

it('lists every test where a file that decided how the gate runs was renamed away', function (): void {
    $renamed = Change::renamed(Path::of('composer.json'), Path::of('composer.json.bak'), Lines::none());

    expect(Affected::texts(Affected::to(Changes::of($renamed))->everyBecause()))
        ->toBe(['`composer.json` decides how the gate runs, so every test is listed.']);
});
