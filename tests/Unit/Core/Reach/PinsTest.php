<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Reach\Pins;

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

it('finds only pins moved when every changed line is an action pinned at a commit', function () use ($workflow, $one, $two): void {
    expect(Pins::onlyMoved(
        $workflow(sprintf('actions/checkout@%s # v4.1.0', $one), sprintf('shivammathur/setup-php@%s', $one), 'composer test'),
        $workflow(sprintf('actions/checkout@%s # v4.2.0', $two), sprintf('shivammathur/setup-php@%s', $two), 'composer test'),
    ))->toBeTrue();
});

it('finds only pins moved when nothing changed', function () use ($workflow, $one): void {
    $same = $workflow(sprintf('actions/checkout@%s', $one), sprintf('shivammathur/setup-php@%s', $one), 'composer test');

    expect(Pins::onlyMoved($same, $same))->toBeTrue();
});

it('finds more than pins moved when another line changed', function () use ($workflow, $one, $two): void {
    expect(Pins::onlyMoved(
        $workflow(sprintf('actions/checkout@%s', $one), sprintf('shivammathur/setup-php@%s', $one), 'composer test'),
        $workflow(sprintf('actions/checkout@%s', $two), sprintf('shivammathur/setup-php@%s', $one), 'composer test:all'),
    ))->toBeFalse();
});

it('finds more than a pin moved when the action it pins changed', function () use ($workflow, $one, $two): void {
    expect(Pins::onlyMoved(
        $workflow(sprintf('actions/checkout@%s', $one), sprintf('shivammathur/setup-php@%s', $one), 'composer test'),
        $workflow(sprintf('actions/cache@%s', $two), sprintf('shivammathur/setup-php@%s', $one), 'composer test'),
    ))->toBeFalse();
});

it('finds more than a pin moved when an action is pinned at a tag', function () use ($workflow, $one): void {
    expect(Pins::onlyMoved(
        $workflow('actions/checkout@v4', sprintf('shivammathur/setup-php@%s', $one), 'composer test'),
        $workflow('actions/checkout@v5', sprintf('shivammathur/setup-php@%s', $one), 'composer test'),
    ))->toBeFalse();
});

it('reads a definition without the commit each action is pinned at and the comment after it, quoted or not', function (): void {
    $pin = str_repeat('4e', 20);
    $definition = Contents::of(sprintf("- uses: a/b@%1\$s # v1\n- uses: 'c/d@%1\$s'\n- uses: e/f@v2 # tag\n", $pin));

    expect(Pins::unpinned($definition))->toBe("- uses: a/b@\n- uses: 'c/d@'\n- uses: e/f@v2 # tag\n");
});
