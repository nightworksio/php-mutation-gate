<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Mago\Mago;
use NightWorksIO\MutationGate\Adapter\PhpStan\PhpStan;
use NightWorksIO\MutationGate\Adapter\Psalm\Psalm;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserSettings;
use NightWorksIO\MutationGate\Core\Analysis\Finding;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\Analysis\MutantCheck;
use NightWorksIO\MutationGate\Core\Analysis\OutOfScope;
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
// it is, the configuration it runs with, written alike wherever the fixture
// lies, with its config file among what it reads, what it reports of the
// original, that a mutant which is no valid
// program has an error the original does not, that one which is has none,
// that each finding names the file it sits in, as the fixture spells it,
// the original's for one in the mutant and a dependent's for one there,
// which the check lists only to an analyser that reads the dependents,
// that a mutant it cannot analyse is left to its tests rather than killed,
// that one of a file outside the paths it analyses, or excluded from them,
// is out of its scope, and that no process it starts sees what the runner
// withholds. One line per implementation. The analysers run where the contract job has
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

/** The configuration the fake says it runs with, under the fixture's root. */
function fakeSettings(string $root): AnalyserSettings
{
    $settings = AnalyserSettings::resolved('{"level": 9}', $root);

    return $settings instanceof AnalyserSettings ? $settings : throw new LogicException('The fake\'s settings are unread.');
}

/** The fake, and the analysers that load the project's code, whose bootstrap sees a variable not withheld. */
$loading = [
    'the fake' => fn(): StaticChecker => new StaticCheckerFake(
        AnalyserIdentity::of('fake', '1.0.0', Digest::sha256Of('{}')),
        Findings::none(),
        [
            $fixture('mutants/Money.invalid.php')->value() => Findings::of(
                Finding::error(Path::of('src/Money.php'), 'return.type', 'Method StaticCheckFixture\Money::add() should return int but returns string.'),
            ),
            $fixture('mutants/Money.valid.php')->value() => Findings::none(),
            $fixture('mutants/Money.retyped.php')->value() => Findings::of(
                Finding::error(Path::of('src/Wallet.php'), 'return.type', 'Method StaticCheckFixture\Wallet::total() should return int but returns string.'),
            ),
            $fixture('mutants/Other.invalid.php')->value() => OutOfScope::of($fixture('outside/Other.php')),
            $fixture('mutants/Excluded.invalid.php')->value() => OutOfScope::of($fixture('src/Excluded.php')),
        ],
        fakeSettings($root)->referencing(Paths::of(Path::of('phpstan.neon'))),
    ),
    ...getenv('ANALYSER_CONTRACTS') === '1' ? [
        'PHPStan' => fn(): StaticChecker => built(PhpStan::fromOptions(Configs::options('{}'), $root)),
        'Psalm' => fn(): StaticChecker => built(Psalm::fromOptions(Configs::options('{}'), $root)),
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

it('says the configuration it runs with, under the fixture\'s root, with its config file among the files it reads', function (StaticChecker $checker) use ($root): void {
    $settings = $checker->configuration(Withheld::standard());
    $references = $settings instanceof AnalyserSettings ? [...$settings->references()] : [];

    expect($settings)->toBeInstanceOf(AnalyserSettings::class)
        ->and($settings instanceof AnalyserSettings ? $settings->written() : $root)->not->toContain($root)
        ->and(array_filter($references, static fn(Path $file): bool => $file->escapes()))->toBe([])
        ->and(array_intersect(array_map(static fn(Path $file): string => $file->value(), $references), ['phpstan.neon', 'mago.toml', 'psalm.xml']))
        ->not->toBe([]);
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

it('names the file each new error sits in: the original for one in the mutant, and a dependent\'s own for one there, listed where it reads them', function (
    StaticChecker $checker,
) use ($fixture): void {
    $original = $checker->findings(Paths::of($fixture('src/Money.php')), Withheld::standard());
    $retyped = MutantCheck::of($fixture('src/Money.php'), $fixture('mutants/Money.retyped.php'));
    $files = static fn(Findings|OutOfScope|CannotJudge $mutant): array => $mutant instanceof Findings && $original instanceof Findings
        ? array_values(array_unique(array_map(static fn(Finding $error): string => $error->file()->value(), [...$mutant->newErrors($original)])))
        : [];

    expect($files($checker->check(MutantCheck::of($fixture('src/Money.php'), $fixture('mutants/Money.invalid.php')))))
        ->toBe(['src/Money.php'])
        ->and($files($checker->check($checker->readsDependents() ? $retyped->withDependents(Paths::of($fixture('src/Wallet.php'))) : $retyped)))
        ->toBe(['src/Wallet.php']);
})->with($checkers);

it('leaves a mutant of a file outside the paths it analyses, or one its config excludes, out of its scope', function (
    StaticChecker $checker,
) use ($fixture): void {
    $original = $checker->findings(Paths::of($fixture('src/Money.php')), Withheld::standard());
    $outside = $checker->check(MutantCheck::of($fixture('outside/Other.php'), $fixture('mutants/Other.invalid.php')));
    $excluded = $checker->check(MutantCheck::of($fixture('src/Excluded.php'), $fixture('mutants/Excluded.invalid.php')));

    expect($original)->toBeInstanceOf(Findings::class)
        ->and($outside)->toEqual(OutOfScope::of($fixture('outside/Other.php')))
        ->and($excluded)->toEqual(OutOfScope::of($fixture('src/Excluded.php')));
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
