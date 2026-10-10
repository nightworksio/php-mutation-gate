<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Reach\AffectedTests;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Reach\TestPlaces;
use NightWorksIO\MutationGate\Core\Report\AffectedFormat;
use NightWorksIO\MutationGate\Core\Report\AffectedText;
use NightWorksIO\MutationGate\Tests\Support\Affected;

/** Two test files of the fixture reached, one of them by two of its tests, and a reason that lists none. */
$reached = static fn(): AffectedTests => AffectedTests::none(Affected::places())
    ->reaching(Path::of('tests/MoneyTest.php'), Affected::ids('MoneyTest::adds', 'MoneyTest::adds nothing'), Reason::that('Money.'))
    ->wholly(Path::of('tests/RateTest.php'), Reason::that('Rate.'))
    ->because(Reason::that('Nothing.'));

it('lists each test file a line, or each ended by a NUL byte', function () use ($reached): void {
    expect(AffectedText::files($reached(), AffectedFormat::Files))->toBe("tests/MoneyTest.php\ntests/RateTest.php\n")
        ->and(AffectedText::files($reached(), AffectedFormat::Files0))->toBe("tests/MoneyTest.php\0tests/RateTest.php\0");
});

it('lists every test file of the suite where every test is listed', function (): void {
    expect(AffectedText::files(AffectedTests::every(Affected::places(), Reason::that('All.')), AffectedFormat::Files))
        ->toBe("tests/MoneyTest.php\ntests/PriceTest.php\ntests/RateTest.php\n");
});

it('lists nothing where no test is reached', function (): void {
    expect(AffectedText::files(AffectedTests::none(Affected::places()), AffectedFormat::Files0))->toBe('')
        ->and(AffectedText::ids(AffectedTests::none(Affected::places())))->toBe('');
});

it('lists each reached test id a line', function () use ($reached): void {
    expect(AffectedText::ids($reached()))->toBe("MoneyTest::adds\nMoneyTest::adds nothing\nRateTest::rates\n");
});

it('cannot list by id a file that holds no test the map names, or a test PHPUnit cannot read back', function (
    AffectedTests $tests,
    string $why,
): void {
    expect(AffectedText::ids($tests))->toEqual(CannotJudge::because($why));
})->with([
    'a file with no ids' => [
        fn(): AffectedTests => AffectedTests::none(TestPlaces::none())->wholly(Path::of('tests/NewTest.php'), Reason::that('New.')),
        '`tests/NewTest.php` holds no test the coverage map names, so --format=ids cannot select it: give --format=files.',
    ],
    'an id with a line break' => [
        fn(): AffectedTests => AffectedTests::none(TestPlaces::none())->reaching(Path::of('tests/RowTest.php'), Affected::ids("RowTest::adds#one\nrow"), Reason::that('Row.')),
        'PHPUnit cannot read back the test id "RowTest::adds#one\nrow" from --test-id-filter-file: give --format=files.',
    ],
    'an id that ends in a carriage return' => [
        fn(): AffectedTests => AffectedTests::none(TestPlaces::none())->reaching(Path::of('tests/RowTest.php'), Affected::ids("RowTest::adds#one\r"), Reason::that('Row.')),
        'PHPUnit cannot read back the test id "RowTest::adds#one\r" from --test-id-filter-file: give --format=files.',
    ],
]);

it('gives each reason a line: each file\'s own after its path, then those that list none, then those that list all', function () use (
    $reached,
): void {
    expect(AffectedText::reasons($reached()->all(Reason::that('All.'))))->toBe([
        'tests/MoneyTest.php: Money.',
        'tests/RateTest.php: Rate.',
        'Nothing.',
        'All.',
    ]);
});

/** The answer that lists a test file at this path, reached whole by a reason that names it. */
function hostileAnswer(string $path): AffectedTests
{
    return AffectedTests::none(TestPlaces::none()->placing(Path::of($path), Affected::ids('HostileTest::runs')))
        ->wholly(Path::of($path), Reason::that(sprintf('`%s` changed.', $path)));
}

it('refuses to list a test file a CI log would read as a command, naming json, rather than change it', function (
    string $path,
    AffectedFormat $format,
    string $why,
): void {
    expect(AffectedText::files(hostileAnswer($path), $format))->toEqual(CannotJudge::because($why));
})->with([
    'a workflow command' => [
        '::set-output name=x::y',
        AffectedFormat::Files,
        'The test file "::set-output name=x::y" would read as a command in a CI log: give --format=json.',
    ],
    'a workflow command, NUL-ended' => [
        '::set-output name=x::y',
        AffectedFormat::Files0,
        'The test file "::set-output name=x::y" would read as a command in a CI log: give --format=json.',
    ],
    'a command after a line break, NUL-ended' => [
        "tests/a\n::add-mask::x.php",
        AffectedFormat::Files0,
        'The test file "tests/a\n::add-mask::x.php" would read as a command in a CI log: give --format=json.',
    ],
]);

it('refuses to list a test file that holds a line break a line each, naming files0', function (): void {
    expect(AffectedText::files(hostileAnswer("tests/a\n::add-mask::x.php"), AffectedFormat::Files))->toEqual(CannotJudge::because(
        'The test file "tests/a\n::add-mask::x.php" holds a line break, so --format=files cannot list it a line each: give --format=files0.',
    ));
});

it('lists a test file whose name holds a command where no line starts it, as it is', function (): void {
    $path = 'tests/::stop-commands::tok.php';

    expect(AffectedText::files(hostileAnswer($path), AffectedFormat::Files))->toBe("tests/::stop-commands::tok.php\n")
        ->and(AffectedText::files(hostileAnswer($path), AffectedFormat::Files0))->toBe("tests/::stop-commands::tok.php\0");
});

it('refuses to list a test id a CI log would read as a command', function (): void {
    $tests = AffectedTests::none(TestPlaces::none())
        ->reaching(Path::of('tests/RowTest.php'), Affected::ids('RowTest::adds#x ##[error]boom'), Reason::that('Row.'));

    expect(AffectedText::ids($tests))
        ->toEqual(CannotJudge::because('The test id "RowTest::adds#x ##[error]boom" would read as a command in a CI log: give --format=json.'));
});

it('gives each reason as one plain line that starts no command, whatever the paths in it hold', function (string $path): void {
    $lines = AffectedText::reasons(hostileAnswer($path));

    expect($lines)->toHaveCount(1)
        ->and($lines[0])->not->toContain("\n")
        ->and($lines[0])->not->toMatch('/^\s*::/')
        ->and($lines[0])->not->toContain('##[');
})->with([
    'a workflow command' => ['::set-output name=x::y'],
    'a command after a line break' => ["tests/a\n::add-mask::x.php"],
    'a command where no line starts it' => ['tests/::stop-commands::tok'],
    'the older form' => ['tests/##[error]x.php'],
]);
