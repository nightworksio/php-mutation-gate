<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Ci\Variables;

it('answers the value of a variable the CI set', function (): void {
    $variables = Variables::of(['SHARD' => '2']);

    expect($variables->has('SHARD'))->toBeTrue()
        ->and($variables->valueOf('SHARD'))->toBe('2');
});

it('reads a variable that is not set as nothing', function (): void {
    $variables = Variables::of([]);

    expect($variables->has('SHARD'))->toBeFalse()
        ->and($variables->valueOf('SHARD'))->toBe('');
});

it('reads a variable set to nothing as not set', function (): void {
    expect(Variables::of(['SHARD' => ''])->has('SHARD'))->toBeFalse();
});

it('is in CI where CI is set to anything, and not where it is unset or empty', function (): void {
    expect(Variables::of(['CI' => 'true'])->inCi())->toBeTrue()
        ->and(Variables::of(['CI' => '1'])->inCi())->toBeTrue()
        ->and(Variables::of(['CI' => ''])->inCi())->toBeFalse()
        ->and(Variables::of([])->inCi())->toBeFalse();
});

it('is on GitHub Actions only where GITHUB_ACTIONS is true', function (): void {
    expect(Variables::of(['GITHUB_ACTIONS' => 'true'])->onGitHubActions())->toBeTrue()
        ->and(Variables::of(['GITHUB_ACTIONS' => 'false'])->onGitHubActions())->toBeFalse()
        ->and(Variables::of(['CI' => 'true'])->onGitHubActions())->toBeFalse();
});
