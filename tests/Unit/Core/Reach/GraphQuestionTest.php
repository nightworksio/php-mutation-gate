<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Coverage\Judges;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Reach\GraphQuestion;

$over = static fn(): GraphQuestion => GraphQuestion::over(
    Paths::of(Path::of('src/Money.php')),
    Paths::of(Path::of('tests/MoneyTest.php')),
    Paths::of(Path::of('tests/Pest.php')),
);

it('asks nothing of the files it is over until told what', function () use ($over): void {
    $question = $over();

    expect($question->asks())->toBeFalse()
        ->and($question->sources())->toEqual(Paths::of(Path::of('src/Money.php')))
        ->and($question->tests())->toEqual(Paths::of(Path::of('tests/MoneyTest.php')))
        ->and($question->always())->toEqual(Paths::of(Path::of('tests/Pest.php')))
        ->and($question->texts())->toBe([])
        ->and($question->map())->toBe([]);
});

it('holds a file the disk no longer holds by its last text, among the test files or the others', function () use ($over): void {
    $source = Contents::of("<?php\nclass Gone {}\n");
    $test = Contents::of("<?php\nit('goes');\n");
    $question = $over()->given(Path::of('src/Gone.php'), $source, test: false)->given(Path::of('tests/GoneTest.php'), $test, test: true);

    expect($question->sources())->toEqual(Paths::of(Path::of('src/Money.php'), Path::of('src/Gone.php')))
        ->and($question->tests())->toEqual(Paths::of(Path::of('tests/MoneyTest.php'), Path::of('tests/GoneTest.php')))
        ->and($question->texts())->toBe(['src/Gone.php' => $source, 'tests/GoneTest.php' => $test])
        ->and($question->asks())->toBeFalse();
});

it('asks what reaches and joins the files it is about, and what the files it names name, keeping the rest', function () use ($over): void {
    $about = $over()->about(Paths::of(Path::of('src/Money.php')));
    $naming = $over()->naming(Paths::of(Path::of('src/Rate.php')));
    $both = $about->naming(Paths::of(Path::of('src/Rate.php')))->about(Paths::of(Path::of('src/Clock.php')));

    expect($about->asks())->toBeTrue()
        ->and($about->reaching())->toEqual(Paths::of(Path::of('src/Money.php')))
        ->and($about->joined())->toEqual(Paths::of(Path::of('src/Money.php')))
        ->and($about->named())->toEqual(Paths::none())
        ->and($naming->asks())->toBeTrue()
        ->and($naming->named())->toEqual(Paths::of(Path::of('src/Rate.php')))
        ->and($naming->reaching())->toEqual(Paths::none())
        ->and($both->reaching())->toEqual(Paths::of(Path::of('src/Money.php'), Path::of('src/Clock.php')))
        ->and($both->named())->toEqual(Paths::of(Path::of('src/Rate.php')))
        ->and($both->sources())->toEqual(Paths::of(Path::of('src/Money.php')));
});

it('is checked by the files the coverage map shows each test file running, keeping what it asks', function () use ($over): void {
    $judges = Judges::none()
        ->judging(Path::of('src/Money.php'), Paths::of(Path::of('tests/MoneyTest.php'), Path::of('tests/LedgerTest.php')))
        ->judging(Path::of('src/Ledger.php'), Paths::of(Path::of('tests/LedgerTest.php')));
    $question = $over()->about(Paths::of(Path::of('src/Money.php')))->checkedBy($judges);

    expect($question->map())->toEqual([
        'tests/MoneyTest.php' => Paths::of(Path::of('src/Money.php')),
        'tests/LedgerTest.php' => Paths::of(Path::of('src/Money.php'), Path::of('src/Ledger.php')),
    ])
        ->and($question->reaching())->toEqual(Paths::of(Path::of('src/Money.php')))
        ->and($question->tests())->toEqual(Paths::of(Path::of('tests/MoneyTest.php')));
});
