<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Proof\Key\Exceptions;
use NightWorksIO\MutationGate\Core\Proof\Key\Ignored;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

$makeExceptions = static fn(): Exceptions => Exceptions::of(Path::of('mutation-gate.json'), Path::of('mutation-gate-baseline.json'), Ignored::globs('docs/**'), Paths::none());

it('leaves out the config file, the baseline, what proofs.ignore matches, every CI definition and the gate\'s own directory', function (string $path) use ($makeExceptions): void {
    $exceptions = $makeExceptions();

    expect($exceptions->leaveOut(Path::of($path)))->toBeTrue();
})->with([
    'mutation-gate.json',
    'mutation-gate-baseline.json',
    'docs/index.md',
    '.github/workflows/ci.yml',
    '.gitlab-ci.yml',
    '.buildkite/pipeline.yml',
    '.circleci/config.yml',
    '.mutation-gate/ledger/refs/heads/main/ledger.json',
    '.mutation-gate/pipeline.yml',
]);

it('leaves out what the gate writes wherever it was told to write it', function (string $path) use ($makeExceptions): void {
    $exceptions = $makeExceptions();

    $written = $exceptions->andWritten(Path::of('build/ledger'))->andWritten(Path::of('reports/mutation.json'));

    expect($written->leaveOut(Path::of($path)))->toBeTrue()
        ->and($exceptions->leaveOut(Path::of($path)))->toBeFalse();
})->with([
    'build/ledger',
    'build/ledger/refs/heads/main/ledger.json',
    'reports/mutation.json',
]);

it('keeps what only starts like a path the gate writes', function () use ($makeExceptions): void {
    $exceptions = $makeExceptions();

    $written = $exceptions->andWritten(Path::of('build/ledger'));

    expect($written->leaveOut(Path::of('build/ledgers.php')))->toBeFalse()
        ->and($written->leaveOut(Path::of('.mutation-gates/x.php')))->toBeFalse()
        ->and($written->leaveOut(Path::of('mutation-gate.json')))->toBeTrue();
});

it('keeps every other file', function (string $path) use ($makeExceptions): void {
    $exceptions = $makeExceptions();

    expect($exceptions->leaveOut(Path::of($path)))->toBeFalse();
})->with([
    'src/Money.php',
    'composer.json',
    '.github/actions/setup/action.yml',
    '.gitlab/mutation-gate.yml',
    'ci/.gitlab-ci.yml',
    'x.github/workflows/ci.yml',
    '.gitlab-ci.yml.orig',
    '.github/workflows-old/ci.yml',
    '.circleci.md',
]);

/** Exceptions whose proofs.ignore matches every file that defines the runner, and docs. */
function exceptionsIgnoringDefinitions(): Exceptions
{
    return Exceptions::of(
        Path::of('mutation-gate.json'),
        Path::of('mutation-gate-baseline.json'),
        Ignored::globs('tests/*', '*.json5', 'phpunit.*', 'docs/**'),
        Paths::of(Path::of('tests/Pest.php'), Path::of('infection.json5'), Path::of('phpunit.xml')),
    );
}

it('keeps every file that defines the runner, whatever proofs.ignore matches', function (string $path): void {
    expect(exceptionsIgnoringDefinitions()->leaveOut(Path::of($path)))->toBeFalse()
        ->and(exceptionsIgnoringDefinitions()->defines(Path::of($path)))->toBeTrue();
})->with(['tests/Pest.php', 'infection.json5', 'phpunit.xml']);

it('still leaves out what proofs.ignore matches that defines no runner', function (string $path): void {
    expect(exceptionsIgnoringDefinitions()->leaveOut(Path::of($path)))->toBeTrue()
        ->and(exceptionsIgnoringDefinitions()->defines(Path::of($path)))->toBeFalse();
})->with(['docs/index.md', 'phpunit.xml.bak', 'tests/fixtures.json5', 'mutation-gate.json']);

it('warns, naming the glob and the file, for each glob that matches a file defining the runner', function (): void {
    $present = Paths::of(Path::of('tests/Pest.php'), Path::of('infection.json5'), Path::of('phpunit.xml'), Path::of('docs/index.md'));

    expect(exceptionsIgnoringDefinitions()->overruled($present))->toEqual(Warnings::of(
        Warning::that('proofs.ignore lists tests/*, which matches tests/Pest.php. That file defines the runner, so every proof key reads it.'),
        Warning::that('proofs.ignore lists *.json5, which matches infection.json5. That file defines the runner, so every proof key reads it.'),
        Warning::that('proofs.ignore lists phpunit.*, which matches phpunit.xml. That file defines the runner, so every proof key reads it.'),
    ));
});

it('warns of nothing where no glob matches a file that defines the runner, or none is present', function (): void {
    $definitions = Paths::of(Path::of('phpunit.xml'));
    $exceptions = Exceptions::of(Path::of('gate.json'), Path::of('baseline.json'), Ignored::globs('docs/**'), $definitions);

    expect($exceptions->overruled($definitions))->toEqual(Warnings::none())
        ->and(exceptionsIgnoringDefinitions()->overruled(Paths::of(Path::of('docs/index.md'))))->toEqual(Warnings::none());
});

it('leaves out no config file under zero-config, and everything else as before', function (): void {
    $baseline = Path::of('mutation-gate-baseline.json');
    $zero = Exceptions::of(Absent::setting(), $baseline, Ignored::nothing(), Paths::none());
    $written = $zero->andWritten(Path::of('reports/mutation.json'));

    expect($zero->leaveOut(Path::of('mutation-gate.json')))->toBeFalse()
        ->and($zero->leaveOut(Path::of('mutation-gate-baseline.json')))->toBeTrue()
        ->and($zero->leaveOut(Path::of('.mutation-gate/plan.json')))->toBeTrue()
        ->and($zero->leaveOut(Path::of('src/Money.php')))->toBeFalse()
        ->and($written->leaveOut(Path::of('reports/mutation.json')))->toBeTrue()
        ->and($written->leaveOut(Path::of('mutation-gate.json')))->toBeFalse();
});
