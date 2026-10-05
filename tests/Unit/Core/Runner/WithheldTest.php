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
    ['GOOGLE_APPLICATION_CREDENTIALS', true],
    ['GOOGLE_GHA_CREDS_PATH', true],
    ['CLOUDSDK_AUTH_CREDENTIAL_FILE_OVERRIDE', true],
    ['AZURE_CLIENT_ID', true],
    ['AZURE_TENANT_ID', true],
    ['MUTATION_GATE_GCS_TOKEN', true],
    ['MUTATION_GATE_AZURE_TOKEN', true],
    ['GOOGLE_CLOUD_PROJECT', false],
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

it('withholds Composer\'s credentials and every secret the gate itself reads from every run', function (string $name) use ($withholds): void {
    expect($withholds(Withheld::standard(), $name))->toBeTrue();
})->with([
    'COMPOSER_AUTH',
    'OTEL_EXPORTER_OTLP_HEADERS',
    'MUTATION_GATE_WEBHOOK_SECRET',
    'MUTATION_GATE_SLACK_URL',
    'MUTATION_GATE_DISCORD_URL',
    'MUTATION_GATE_WEBHOOK_URL',
    'GH_TOKEN',
]);

it('leaves a variable that holds no credential to the tests, though it shares a secret\'s prefix', function (string $name) use ($withholds): void {
    expect($withholds(Withheld::standard(), $name))->toBeFalse();
})->with(['MUTATION_GATE_RESULTS', 'MUTATION_GATE_TEAMS_URL', 'OTEL_EXPORTER_OTLP_ENDPOINT', 'COMPOSER_HOME']);

it('withholds what both withhold, each once, and only grows', function () use ($withholds): void {
    $both = Withheld::standard()->and(Withheld::of('CI_JOB_TOKEN', 'GITHUB_TOKEN'));

    expect($both->pattern())->toBe('~^(?:AWS_.*|ACTIONS_.*|GITHUB_TOKEN|SONAR_TOKEN|COMPOSER_AUTH|GOOGLE_APPLICATION_CREDENTIALS|GOOGLE_GHA_CREDS_PATH|CLOUDSDK_AUTH_CREDENTIAL_FILE_OVERRIDE|AZURE_.*|MUTATION_GATE_GCS_TOKEN|MUTATION_GATE_AZURE_TOKEN|OTEL_EXPORTER_OTLP_HEADERS|MUTATION_GATE_SLACK_URL|MUTATION_GATE_DISCORD_URL|MUTATION_GATE_WEBHOOK_URL|MUTATION_GATE_WEBHOOK_SECRET|GH_TOKEN|CI_JOB_TOKEN)$~')
        ->and($withholds($both, 'CI_JOB_TOKEN'))->toBeTrue()
        ->and($withholds(Withheld::standard(), 'CI_JOB_TOKEN'))->toBeFalse();
});

it('composes what every run withholds, each CI plan\'s credentials and the runner\'s, each once', function (): void {
    expect([...Withheld::composed(
        Withheld::of('DEPLOY_*', 'CI_JOB_TOKEN'),
        Withheld::of('CI_JOB_TOKEN'),
        Withheld::of('BUILDKITE_AGENT_TOKEN'),
    )])->toBe([...Withheld::standard(), 'CI_JOB_TOKEN', 'BUILDKITE_AGENT_TOKEN', 'DEPLOY_*'])
        ->and([...Withheld::composed(Withheld::nothing())])->toBe([...Withheld::standard()]);
});

it('withholds what makes a process another run\'s worker or mutant', function (string $name, bool $withheld) use ($withholds): void {
    expect($withholds(Withheld::otherRuns(), $name))->toBe($withheld);
})->with([
    'the gate\'s own' => ['MUTATION_GATE_RESULTS', true],
    'Infection\'s' => ['INFECTION_MUTANT', true],
    'pest-plugin-mutate\'s' => ['PEST_MUTATION_TESTING', true],
    'paratest\'s' => ['PARATEST', true],
    'a paratest worker\'s token' => ['TEST_TOKEN', true],
    'a paratest worker\'s unique token' => ['UNIQUE_TEST_TOKEN', true],
    'Laravel\'s parallel testing' => ['LARAVEL_PARALLEL_TESTING', true],
    'a name that only ends in one' => ['MY_TEST_TOKEN', false],
    'the path' => ['PATH', false],
]);
