<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Proof\Key\Exceptions;
use NightWorksIO\MutationGate\Core\Proof\Key\Ignored;

$exceptions = Exceptions::of(Path::of('mutation-gate.json'), Path::of('mutation-gate-baseline.json'), Ignored::globs('docs/**'));

it('leaves out the config file, the baseline, what proofs.ignore matches and every CI definition', function (string $path) use ($exceptions): void {
    expect($exceptions->leaveOut(Path::of($path)))->toBeTrue();
})->with([
    'mutation-gate.json',
    'mutation-gate-baseline.json',
    'docs/index.md',
    '.github/workflows/ci.yml',
    '.gitlab-ci.yml',
    '.buildkite/pipeline.yml',
    '.circleci/config.yml',
]);

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
