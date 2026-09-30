<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\Withheld;

$withholds = static fn(Withheld $withheld, string $name): bool => preg_match($withheld->pattern(), $name) === 1;

it('withholds the CI\'s credentials every run withholds, by the whole name', function (string $name, bool $withheld) use ($withholds): void {
    expect($withholds(Withheld::standard(), $name))->toBe($withheld);
})->with([
    ['AWS_SECRET_ACCESS_KEY', true],
    ['AWS_', true],
    ['ACTIONS_RUNTIME_TOKEN', true],
    ['GITHUB_TOKEN', true],
    ['SONAR_TOKEN', true],
    ['MY_GITHUB_TOKEN', false],
    ['GITHUB_TOKENS', false],
    ['GITHUB_SHA', false],
    ['PATH', false],
]);

it('withholds names and globs as they are written, however they look to a pattern', function () use ($withholds): void {
    $withheld = Withheld::of('DEPLOY_*', 'A.B', 'x~y');

    expect($withholds($withheld, 'DEPLOY_KEY'))->toBeTrue()
        ->and($withholds($withheld, 'A.B'))->toBeTrue()
        ->and($withholds($withheld, 'AxB'))->toBeFalse()
        ->and($withholds($withheld, 'x~y'))->toBeTrue()
        ->and($withheld->pattern())->toBe('~^(?:DEPLOY_.*|A\.B|x\~y)$~');
});

it('withholds nothing where nothing is withheld', function () use ($withholds): void {
    expect($withholds(Withheld::nothing(), ''))->toBeFalse()
        ->and($withholds(Withheld::nothing(), 'AWS_SECRET_ACCESS_KEY'))->toBeFalse();
});

it('withholds what both withhold, each once, and only grows', function () use ($withholds): void {
    $both = Withheld::standard()->and(Withheld::of('CI_JOB_TOKEN', 'GITHUB_TOKEN'));

    expect($both->pattern())->toBe('~^(?:AWS_.*|ACTIONS_.*|GITHUB_TOKEN|SONAR_TOKEN|CI_JOB_TOKEN)$~')
        ->and($withholds($both, 'CI_JOB_TOKEN'))->toBeTrue()
        ->and($withholds(Withheld::standard(), 'CI_JOB_TOKEN'))->toBeFalse();
});
