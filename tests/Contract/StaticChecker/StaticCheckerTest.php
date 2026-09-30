<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\Finding;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\Analysis\MutantCheck;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Port\StaticChecker;
use NightWorksIO\MutationGate\Tests\Fakes\StaticCheckerFake;
use NightWorksIO\MutationGate\Tests\Support\Tree;

// What every static analyser answers over the fixture in this directory: who
// it is, what it reports of the original, that a mutant which is no valid
// program has an error the original does not, that one which is has none,
// that a mutant it cannot analyse leaves the mutant to its tests rather than
// killing it, and that no process it starts sees what the runner withholds.
// One line per implementation.

$fixture = static fn(string $path): Path => Path::of(Tree::at(sprintf('tests/Contract/StaticChecker/fixture/%s', $path)));

afterEach(function (): void {
    putenv(StaticCheckerFake::LEAK);
});

$checkers = [
    'the fake' => fn(): StaticChecker => new StaticCheckerFake(
        AnalyserIdentity::of('fake', '1.0.0', Digest::sha256Of('{}')),
        Findings::none(),
        [
            $fixture('mutants/Money.invalid.php')->value() => Findings::of(
                Finding::error('return.type', 'Method Acme\Money::add() should return int but returns string.'),
            ),
            $fixture('mutants/Money.valid.php')->value() => Findings::none(),
        ],
    ),
];

it('names the analyser and its exact version', function (StaticChecker $checker): void {
    $identity = $checker->identity(Withheld::standard());

    expect($identity)->toBeInstanceOf(AnalyserIdentity::class)
        ->and($identity instanceof AnalyserIdentity ? [$identity->analyser(), $identity->version()] : [])->not->toContain('');
})->with($checkers);

it('rejects a mutant that is no valid program, by an error the original does not have', function (StaticChecker $checker) use ($fixture): void {
    $withheld = Withheld::standard();
    $original = $checker->findings(Paths::of($fixture('src/Money.php')), $withheld);
    $mutant = $checker->check(MutantCheck::of($fixture('src/Money.php'), $fixture('mutants/Money.invalid.php')));

    expect($original)->toBeInstanceOf(Findings::class)
        ->and($mutant instanceof Findings && $original instanceof Findings && $mutant->rejects($original))->toBeTrue();
})->with($checkers);

it('leaves a mutant that is a valid program to its tests', function (StaticChecker $checker) use ($fixture): void {
    $withheld = Withheld::standard();
    $original = $checker->findings(Paths::of($fixture('src/Money.php')), $withheld);
    $mutant = $checker->check(MutantCheck::of($fixture('src/Money.php'), $fixture('mutants/Money.valid.php')));

    expect($mutant instanceof Findings && $original instanceof Findings && $mutant->rejects($original))->toBeFalse()
        ->and($mutant)->toBeInstanceOf(Findings::class);
})->with($checkers);

it('cannot judge a mutant it cannot analyse, so the mutant goes to its tests', function (StaticChecker $checker) use ($fixture): void {
    expect($checker->check(MutantCheck::of($fixture('src/Money.php'), $fixture('mutants/Money.missing.php'))))
        ->toBeInstanceOf(CannotJudge::class);
})->with($checkers);

it('starts every process without what the runner withholds', function (StaticChecker $checker) use ($fixture): void {
    putenv(sprintf('%s=leaked', StaticCheckerFake::LEAK));
    $withheld = Withheld::standard()->and(Withheld::of(StaticCheckerFake::LEAK));
    $check = MutantCheck::of($fixture('src/Money.php'), $fixture('mutants/Money.invalid.php'));

    expect($checker->identity($withheld))->toBeInstanceOf(AnalyserIdentity::class)
        ->and($checker->findings(Paths::of($fixture('src/Money.php')), $withheld))->toBeInstanceOf(Findings::class)
        ->and($checker->check($check->withholding($withheld)))->toBeInstanceOf(Findings::class)
        ->and($checker->identity(Withheld::standard()))->toBeInstanceOf(CannotJudge::class)
        ->and($checker->findings(Paths::of($fixture('src/Money.php')), Withheld::standard()))
        ->toBeInstanceOf(CannotJudge::class)
        ->and($checker->check($check))->toBeInstanceOf(CannotJudge::class);
})->with($checkers);
