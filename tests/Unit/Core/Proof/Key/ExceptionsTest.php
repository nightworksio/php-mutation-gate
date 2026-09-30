<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Proof\Key\Exceptions;
use NightWorksIO\MutationGate\Core\Proof\Key\Ignored;

$exceptions = Exceptions::of(Path::of('mutation-gate.json'), Path::of('mutation-gate-baseline.json'), Ignored::globs('docs/**'));

it('leaves out the config file, the baseline, what proofs.ignore matches, every CI definition and the gate\'s own directory', function (string $path) use ($exceptions): void {
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

it('leaves out what the gate writes wherever it was told to write it', function (string $path) use ($exceptions): void {
    $written = $exceptions->andWritten(Path::of('build/ledger'))->andWritten(Path::of('reports/mutation.json'));

    expect($written->leaveOut(Path::of($path)))->toBeTrue()
        ->and($exceptions->leaveOut(Path::of($path)))->toBeFalse();
})->with([
    'build/ledger',
    'build/ledger/refs/heads/main/ledger.json',
    'reports/mutation.json',
]);

it('keeps what only starts like a path the gate writes', function () use ($exceptions): void {
    $written = $exceptions->andWritten(Path::of('build/ledger'));

    expect($written->leaveOut(Path::of('build/ledgers.php')))->toBeFalse()
        ->and($written->leaveOut(Path::of('.mutation-gates/x.php')))->toBeFalse()
        ->and($written->leaveOut(Path::of('mutation-gate.json')))->toBeTrue();
});

it('keeps every other file', function (string $path) use ($exceptions): void {
    expect($exceptions->leaveOut(Path::of($path)))->toBeFalse();
})->with([
    'src/Money.php',
    'composer.json',
    '.github/actions/setup/action.yml',
    '.gitlab/mutation-gate.yml',
    'ci/.gitlab-ci.yml',
    'x.github/workflows/ci.yml',
]);
