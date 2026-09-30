<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Mago\Mago;
use NightWorksIO\MutationGate\Adapter\PhpStan\PhpStan;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\Finding;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\Analysis\MutantCheck;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Port\StaticChecker;
use NightWorksIO\MutationGate\Tests\Fakes\StaticCheckerFake;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Tree;

// What every static analyser answers over the fixture in this directory: who
// it is, what it reports of the original, that a mutant which is no valid
// program has an error the original does not, that one which is has none,
// that a mutant it cannot analyse leaves the mutant to its tests rather than
// killing it, and that no process it starts sees what the runner withholds.
// One line per implementation. The analysers run where the contract job has
// installed them into the fixture, which ANALYSER_CONTRACTS says.

$fixture = static fn(string $path): Path => Path::of(Tree::at(sprintf('tests/Contract/StaticChecker/fixture/%s', $path)));

afterEach(function (): void {
    putenv(StaticCheckerFake::LEAK);
});

$root = Tree::at('tests/Contract/StaticChecker/fixture');

/** An analyser as the gate builds it over the fixture, which is never refused. */
function built(StaticChecker|Invalid $analyser): StaticChecker
{
    return $analyser instanceof StaticChecker ? $analyser : throw new LogicException('The fixture\'s options are refused.');
}

/** The fake, and the analysers that load the project's code, whose bootstrap sees a variable not withheld. */
$loading = [
    'the fake' => fn(): StaticChecker => new StaticCheckerFake(
        AnalyserIdentity::of('fake', '1.0.0', Digest::sha256Of('{}')),
        Findings::none(),
        [
            $fixture('mutants/Money.invalid.php')->value() => Findings::of(
                Finding::error('return.type', 'Method StaticCheckFixture\Money::add() should return int but returns string.'),
            ),
            $fixture('mutants/Money.valid.php')->value() => Findings::none(),
        ],
    ),
    ...getenv('ANALYSER_CONTRACTS') === '1' ? [
        'PHPStan' => fn(): StaticChecker => built(PhpStan::fromOptions(Configs::options('{}'), $root)),
    ] : [],
];

$checkers = [
    ...$loading,
    ...getenv('ANALYSER_CONTRACTS') === '1' ? [
        'Mago' => fn(): StaticChecker => built(Mago::fromOptions(Configs::options('{}'), $root, sprintf('%s/vendor', $root))),
    ] : [],
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
        ->and($checker->check($check->withholding($withheld)))->toBeInstanceOf(Findings::class);
})->with($checkers);

it('lets the project\'s code see a variable it does not withhold, so the check above can fail', function (StaticChecker $checker) use ($fixture): void {
    putenv(sprintf('%s=leaked', StaticCheckerFake::LEAK));
    $check = MutantCheck::of($fixture('src/Money.php'), $fixture('mutants/Money.invalid.php'));

    expect($checker->findings(Paths::of($fixture('src/Money.php')), Withheld::standard()))->toBeInstanceOf(CannotJudge::class)
        ->and($checker->check($check))->toBeInstanceOf(CannotJudge::class);
})->with($loading);
