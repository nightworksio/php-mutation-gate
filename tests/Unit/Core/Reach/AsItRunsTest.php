<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Reach\AsItRuns;

$workflow = static fn(string $checkout, string $php, string $run): Contents => Contents::of(sprintf(<<<'YAML'
    jobs:
      gate:
        steps:
          - uses: %s
          - uses: '%s'
          - run: %s
    YAML, $checkout, $php, $run));

$one = str_repeat('a', 40);
$two = str_repeat('b', 40);

it('runs alike where every changed line is an action pinned at a commit', function () use ($workflow, $one, $two): void {
    expect(AsItRuns::alike(
        $workflow(sprintf('actions/checkout@%s # v4.1.0', $one), sprintf('shivammathur/setup-php@%s', $one), 'composer test'),
        $workflow(sprintf('actions/checkout@%s # v4.2.0', $two), sprintf('shivammathur/setup-php@%s', $two), 'composer test'),
    ))->toBeTrue();
});

it('runs alike where nothing changed', function () use ($workflow, $one): void {
    $same = $workflow(sprintf('actions/checkout@%s', $one), sprintf('shivammathur/setup-php@%s', $one), 'composer test');

    expect(AsItRuns::alike($same, $same))->toBeTrue();
});

it('runs otherwise where another line changed', function () use ($workflow, $one, $two): void {
    expect(AsItRuns::alike(
        $workflow(sprintf('actions/checkout@%s', $one), sprintf('shivammathur/setup-php@%s', $one), 'composer test'),
        $workflow(sprintf('actions/checkout@%s', $two), sprintf('shivammathur/setup-php@%s', $one), 'composer test:all'),
    ))->toBeFalse();
});

it('runs otherwise where the action a pin names changed', function () use ($workflow, $one, $two): void {
    expect(AsItRuns::alike(
        $workflow(sprintf('actions/checkout@%s', $one), sprintf('shivammathur/setup-php@%s', $one), 'composer test'),
        $workflow(sprintf('actions/cache@%s', $two), sprintf('shivammathur/setup-php@%s', $one), 'composer test'),
    ))->toBeFalse();
});

it('runs otherwise where an action is pinned at a tag', function () use ($workflow, $one): void {
    expect(AsItRuns::alike(
        $workflow('actions/checkout@v4', sprintf('shivammathur/setup-php@%s', $one), 'composer test'),
        $workflow('actions/checkout@v5', sprintf('shivammathur/setup-php@%s', $one), 'composer test'),
    ))->toBeFalse();
});

it('reads a definition without the commit each action is pinned at and the comment after it, quoted or not', function (): void {
    $pin = str_repeat('4e', 20);
    $definition = Contents::of(sprintf("- uses: a/b@%1\$s # v1\n- uses: 'c/d@%1\$s'\n- uses: e/f@v2 # tag\n", $pin));

    expect(AsItRuns::text($definition))->toBe("- uses: a/b@\n- uses: 'c/d@'\n- uses: e/f@v2 # tag");
});

it('reads a definition without its comment lines and blank lines, and runs alike where only those changed', function (): void {
    $before = Contents::of("# The gate.\njobs:\n  gate:\n    steps:\n      - run: composer test # unit\n");
    $after = Contents::of("jobs:\n\n  # One job.\n  gate:\n    steps:\n      - run: composer test # unit\n   \n");

    expect(AsItRuns::text($before))->toBe("jobs:\n  gate:\n    steps:\n      - run: composer test # unit")
        ->and(AsItRuns::alike($before, $after))->toBeTrue()
        ->and(AsItRuns::alike($before, Contents::of("jobs:\n  gate:\n    steps:\n      - run: composer test\n")))->toBeFalse();
});
