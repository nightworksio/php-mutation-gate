<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Reach\Sources;
use NightWorksIO\MutationGate\Core\Reach\TestsChanged;
use NightWorksIO\MutationGate\Core\Reach\Widening;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestMethod;

/** A Pest test file, its helper returning this, its tests these, each a line. */
function changedPest(string $helper, string ...$tests): string
{
    return sprintf("<?php\n\nuse App\\Money;\n\nfunction money(): int\n{\n    return %s;\n}\n\n%s\n", $helper, implode("\n\n", $tests));
}

/** The coverage map's ids of these Pest tests of `tests/MoneyTest.php`, each by its description. */
function changedIds(string ...$descriptions): TestIds
{
    return TestIds::of(...array_map(
        static fn(string $description): TestId => TestMethod::id('P\Tests\MoneyTest', TestMethod::pest($description)),
        $descriptions,
    ));
}

/** What a change to `tests/MoneyTest.php` from one text to another touched, of these placed tests. */
function changedBetween(string $before, string $now, TestIds $placed): TestIds|Widening
{
    $path = Path::of('tests/MoneyTest.php');
    $sources = Sources::none()->withBefore($path, Contents::of($before))->withNow($path, Contents::of($now));

    return TestsChanged::of(Change::modified($path, Lines::of(Line::of(1))), $sources, $placed);
}

/** A test of the file, which adds. */
const CHANGED_ADDS = "it('adds', fn () => expect(money() + 1)->toBe(2));";

/** A test of the file, which rounds. */
const CHANGED_ROUNDS = "it('rounds', fn () => expect(money())->toBe(1));";

$adds = CHANGED_ADDS;
$rounds = CHANGED_ROUNDS;

it('touches only the tests whose declarations differ, with every row of a data set among them', function () use ($adds, $rounds): void {
    $placed = changedIds('it adds', 'it rounds')
        ->with(TestId::of(sprintf('P\Tests\MoneyTest::%s#0', TestMethod::pest('it adds'))));
    $changed = str_replace('+ 1', '+ 2', $adds);

    expect(changedBetween(changedPest('1', $adds, $rounds), changedPest('1', $changed, $rounds), $placed))
        ->toEqual(changedIds('it adds')->with(TestId::of(sprintf('P\Tests\MoneyTest::%s#0', TestMethod::pest('it adds')))));
});

it('touches a test the map places that stands in one version alone, and none where no test differs', function () use ($adds, $rounds): void {
    $placed = changedIds('it adds', 'it rounds');

    expect(changedBetween(changedPest('1', $adds), changedPest('1', $adds, $rounds), $placed))->toEqual(changedIds('it rounds'))
        ->and(changedBetween(changedPest('1', $adds, $rounds), changedPest('1', $adds), $placed))->toEqual(changedIds('it rounds'))
        ->and(changedBetween(changedPest('1', $adds, $rounds), changedPest('1', $adds, $rounds), $placed))->toEqual(TestIds::none());
});

it('touches every test where the change moved what they share', function (string $now): void {
    expect(changedBetween(changedPest('1', CHANGED_ADDS, CHANGED_ROUNDS), $now, changedIds('it adds', 'it rounds')))->toBe(Widening::Shared);
})->with([
    'a helper' => [fn(): string => changedPest('2', CHANGED_ADDS, CHANGED_ROUNDS)],
    'an import changed' => [fn(): string => str_replace('use App\\Money;', 'use App\\Rate;', changedPest('1', CHANGED_ADDS, CHANGED_ROUNDS))],
    'an import removed' => [fn(): string => str_replace("use App\\Money;\n", '', changedPest('1', CHANGED_ADDS, CHANGED_ROUNDS))],
    'an import added twice over one' => [fn(): string => str_replace('use App\\Money;', "use App\\Rate;\nuse App\\Rate;", changedPest('1', CHANGED_ADDS, CHANGED_ROUNDS))],
    'an import added with a helper changed' => [fn(): string => str_replace('use App\\Money;', "use App\\Money;\nuse App\\Rate;", changedPest('2', CHANGED_ADDS, CHANGED_ROUNDS))],
    'a hook' => [fn(): string => changedPest('1', 'beforeEach(fn () => 1);', CHANGED_ADDS, CHANGED_ROUNDS)],
    'a test the map does not place' => [fn(): string => changedPest('1', CHANGED_ADDS, CHANGED_ROUNDS, "it('pays', fn () => 1);")],
    'a test renamed' => [fn(): string => changedPest('1', CHANGED_ADDS, str_replace('rounds', 'rounds down', CHANGED_ROUNDS))],
]);

it('touches no test for imports a change only added, wherever it put them and however it laid them out, and only the test that changed besides', function (string $now): void {
    expect(changedBetween(changedPest('1', CHANGED_ADDS, CHANGED_ROUNDS), $now, changedIds('it adds', 'it rounds')))->toEqual(TestIds::none());
})->with([
    'after the import' => [fn(): string => str_replace('use App\\Money;', "use App\\Money;\nuse App\\Rate;", changedPest('1', CHANGED_ADDS, CHANGED_ROUNDS))],
    'before it, the file laid out anew' => [fn(): string => str_replace("\n\nuse App\\Money;", "\n\nuse function App\\rate;\n\nuse   App\\Money;", changedPest('1', CHANGED_ADDS, CHANGED_ROUNDS))],
    'grouped' => [fn(): string => str_replace('use App\\Money;', "use App\\Money;\nuse App\\{Rate, Fee};", changedPest('1', CHANGED_ADDS, CHANGED_ROUNDS))],
]);

it('touches only the test that changed where a change also added an import', function (): void {
    $now = str_replace('use App\\Money;', "use App\\Money;\nuse App\\Rate;", changedPest('1', str_replace('+ 1', '+ 2', CHANGED_ADDS), CHANGED_ROUNDS));

    expect(changedBetween(changedPest('1', CHANGED_ADDS, CHANGED_ROUNDS), $now, changedIds('it adds', 'it rounds')))->toEqual(changedIds('it adds'));
});

it('touches every test of a PHPUnit class whose setUp moved, and only the test whose body did', function (): void {
    $class = static fn(string $setUp, string $body): string => sprintf(
        "<?php\n\nfinal class MoneyTest extends TestCase\n{\n    protected function setUp(): void\n    {\n        %s\n    }\n\n    public function testAdds(): void\n    {\n        %s\n    }\n\n    public function testRounds(): void\n    {\n        expect(true)->toBeTrue();\n    }\n}\n",
        $setUp,
        $body,
    );
    $placed = TestIds::of(TestId::of('MoneyTest::testAdds'), TestId::of('MoneyTest::testRounds'));

    expect(changedBetween($class('$a = 1;', 'expect(1)->toBe(1);'), $class('$a = 2;', 'expect(1)->toBe(1);'), $placed))->toBe(Widening::Shared)
        ->and(changedBetween($class('$a = 1;', 'expect(1)->toBe(1);'), $class('$a = 1;', 'expect(2)->toBe(2);'), $placed))
        ->toEqual(TestIds::of(TestId::of('MoneyTest::testAdds')));
});

it('touches every test where one changed and a test may depend on another', function () use ($adds, $rounds): void {
    $depending = "it('rounds', fn () => expect(money())->toBe(1))->depends('it adds');";

    expect(changedBetween(changedPest('1', $adds, $depending), changedPest('1', str_replace('+ 1', '+ 2', $adds), $depending), changedIds('it adds', 'it rounds')))
        ->toBe(Widening::Dependent)
        ->and(changedBetween(changedPest('1', $adds, $rounds), changedPest('1', $adds, $depending), changedIds('it adds', 'it rounds')))
        ->toBe(Widening::Dependent)
        ->and(changedBetween(changedPest('1', $adds, $depending), changedPest('1', $adds, $rounds), changedIds('it adds', 'it rounds')))
        ->toBe(Widening::Dependent)
        ->and(changedBetween(changedPest('1', $adds, $depending), changedPest('1', $adds, $depending), changedIds('it adds', 'it rounds')))
        ->toEqual(TestIds::none());
});

it('touches every test of a file that is new, was renamed, or cannot be read in both versions', function () use ($adds): void {
    $path = Path::of('tests/MoneyTest.php');
    $text = Contents::of(changedPest('1', $adds));
    $placed = changedIds('it adds');

    expect(TestsChanged::of(Change::added($path, Lines::of(Line::of(1))), Sources::none()->withNow($path, $text), $placed))->toBe(Widening::New)
        ->and(TestsChanged::of(Change::renamed(Path::of('tests/OldTest.php'), $path, Lines::none()), Sources::none()->withNow($path, $text)->withBefore(Path::of('tests/OldTest.php'), $text), $placed))->toBe(Widening::Renamed)
        ->and(TestsChanged::of(Change::modified($path, Lines::none()), Sources::none()->withNow($path, $text), $placed))->toBe(Widening::Unread)
        ->and(TestsChanged::of(Change::modified($path, Lines::none()), Sources::none()->withBefore($path, $text), $placed))->toBe(Widening::Unread);
});

it('keys a test whose id names no method by the id, and touches none it cannot find in either version', function () use ($adds): void {
    $placed = changedIds('it adds')->with(TestId::of('tests/money.phpt'));

    expect(changedBetween(changedPest('1', $adds), changedPest('1', str_replace('+ 1', '+ 3', $adds)), $placed))->toEqual(changedIds('it adds'));
});

it('touches every test where an import is declared a different number of times', function (): void {
    $twice = str_replace('use App\Money;', "use App\\Money;\nuse App\\Money;", changedPest('1', CHANGED_ADDS, CHANGED_ROUNDS));

    expect(changedBetween($twice, changedPest('1', CHANGED_ADDS, CHANGED_ROUNDS), changedIds('it adds', 'it rounds')))->toBe(Widening::Shared)
        ->and(changedBetween(changedPest('1', CHANGED_ADDS, CHANGED_ROUNDS), $twice, changedIds('it adds', 'it rounds')))->toBe(Widening::Shared);
});

it('touches every test where an added import gives a name the file spelt before, and none where the name is new', function (string $import): void {
    $uses = "it('adds', fn () => expect(new Foo())->toBeObject());";
    $imported = static fn(string $file): string => str_replace('use App\Money;', sprintf("use App\\Money;\n%s", $import), $file);

    expect(changedBetween(changedPest('1', $uses, CHANGED_ROUNDS), $imported(changedPest('1', $uses, CHANGED_ROUNDS)), changedIds('it adds', 'it rounds')))
        ->toBe(Widening::Shared)
        ->and(changedBetween(changedPest('1', CHANGED_ADDS, CHANGED_ROUNDS), $imported(changedPest('1', CHANGED_ADDS, CHANGED_ROUNDS)), changedIds('it adds', 'it rounds')))
        ->toEqual(TestIds::none());
})->with([
    'by its last segment' => ['use Other\\Foo;'],
    'by its alias' => ['use Other\\Bar as Foo;'],
    'in a group' => ['use Other\\{Bar, Foo};'],
    'in another case' => ['use Other\\FOO;'],
]);

it('touches every test where an added function import names a function a helper the tests share calls', function (): void {
    $now = str_replace('use App\Money;', "use App\\Money;\nuse function Other\\money;", changedPest('1', CHANGED_ADDS, CHANGED_ROUNDS));

    expect(changedBetween(changedPest('1', CHANGED_ADDS, CHANGED_ROUNDS), $now, changedIds('it adds', 'it rounds')))->toBe(Widening::Shared);
});
