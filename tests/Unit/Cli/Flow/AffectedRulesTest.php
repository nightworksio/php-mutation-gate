<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\AffectedRules;
use NightWorksIO\MutationGate\Cli\Flow\AskedChanges;
use NightWorksIO\MutationGate\Cli\Flow\Inventory;
use NightWorksIO\MutationGate\Cli\Flow\TestsOfTheSuite;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Support\Affected;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** The fixture's files: `src/Price.php` reads `App\Money::RATE` on its line 7, and `tests/RateTest.php` reads it itself. */
const AFFECTED_RULES_FILES = [...Flows::FILES, ...Affected::FILES];

/** What the runner selects for a changed file the map covers. */
const AFFECTED_RULES_JUDGES = [
    ['tests/DrainSpec.php', [], ['`src/Money.php` changed, and the runner selects this file to judge it.']],
    ['tests/MoneySpec.php', [], ['`src/Money.php` changed, and the runner selects this file to judge it.']],
];

/**
 * The tests these changes since the map's commit reach, over a project of
 * these files, where `MoneyTest::adds` runs Money's line 11 and Price's line 7.
 *
 * @param  array<string, string>                              $now
 * @return list<array{string, list<string>, list<string>}>
 */
function affectedByRules(array $now, Change ...$changes): array
{
    $project = Scratch::directory();

    foreach ($now as $path => $text) {
        Scratch::write($project, $path, $text);
    }

    $at = Revision::ref('measured');
    $checkout = new ChangeSourceFake($at, Changes::of(...$changes), [Revision::workingTree()->name() => $now, 'measured' => AFFECTED_RULES_FILES]);
    $adapters = Flows::adapters($project, [], $checkout);
    $settings = Flows::settings();
    $read = Inventory::of($adapters, $settings);
    $inventory = $read instanceof Inventory ? $read : throw new LogicException($read->why());
    $map = CoverageMap::empty()
        ->covered(Path::of('src/Money.php'), Line::of(11), TestId::of('MoneyTest::adds'))
        ->covered(Path::of('src/Price.php'), Line::of(7), TestId::of('MoneyTest::adds'));
    $places = TestsOfTheSuite::placed($adapters, $inventory->suite, $map);

    return Affected::listed(new AffectedRules($adapters, $settings, $inventory, $map)
        ->of(AskedChanges::of(Changes::of(...$changes), $at, Changes::none(), NotGiven::value()), $places));
}

it('lists a test file that reads a changed value itself, and the tests that run a line of source that reads it', function (): void {
    expect(affectedByRules(AFFECTED_RULES_FILES, Change::modified(Path::of('src/Money.php'), Lines::none())))->toBe([
        ['tests/RateTest.php', [], ['`src/Money.php` declares constant App\\Money::RATE, which these tests read.']],
        [
            'tests/MoneyTest.php',
            ['MoneyTest::adds'],
            ['`src/Money.php` declares constant App\\Money::RATE, read on line 7 of `src/Price.php`, which these tests run.'],
        ],
        ...AFFECTED_RULES_JUDGES,
    ]);
});

it('reads a changed test file as a test', function (): void {
    $changes = [Change::modified(Path::of('src/Money.php'), Lines::none()), Change::modified(Path::of('tests/RateTest.php'), Lines::none())];

    expect(affectedByRules(AFFECTED_RULES_FILES, ...$changes)[0])->toBe([
        'tests/RateTest.php',
        [],
        ['`src/Money.php` declares constant App\\Money::RATE, which these tests read.', '`tests/RateTest.php` changed.'],
    ]);
});

it('follows a changed value through every changed source file that reads it, though the map runs none of them', function (): void {
    $rates = "<?php\n\nnamespace App;\n\nfinal class Rates\n{\n    public const DOUBLE = Money::RATE * 2;\n}\n";
    $double = "<?php\n\nuse App\\Rates;\n\nit('doubles', fn () => expect(Rates::DOUBLE)->toBe(4));\n";
    $files = [...AFFECTED_RULES_FILES, 'src/Rates.php' => $rates, 'tests/DoubleTest.php' => $double];
    $changes = [Change::added(Path::of('src/Rates.php'), Lines::none()), Change::modified(Path::of('src/Money.php'), Lines::none())];

    expect(affectedByRules($files, ...$changes)[0])->toBe([
        'tests/DoubleTest.php',
        [],
        ['`src/Rates.php` is new, and these tests name what it declares.', '`src/Money.php` declares constant App\\Money::RATE, which these tests read.'],
    ]);
});
