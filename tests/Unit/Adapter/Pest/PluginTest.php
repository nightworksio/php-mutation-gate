<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Plugin;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Guard;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Off;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Tests\Support\Tree;

it('records and guards nothing until Pest boots it', function (): void {
    expect(new Plugin()->recorder())->toBe(Off::Recording)
        ->and(new Plugin()->guard())->toBe(Off::Guarding);
});

it('guards a run the adapter starts on one mutant, writing what it saw when the run ends', function (): void {
    $variables = ['PEST_MUTATION_TESTING' => getenv('PEST_MUTATION_TESTING'), 'MUTATION_GATE_GUARD' => false];
    $guard = sprintf('%s/mutation-gate-plugin-guard.json', sys_get_temp_dir());
    $plugin = new Plugin();

    try {
        putenv(sprintf('PEST_MUTATION_TESTING=%s', __FILE__));
        putenv(sprintf('MUTATION_GATE_GUARD=%s', $guard));
        $plugin->boot();
    } finally {
        foreach ($variables as $name => $value) {
            putenv(is_string($value) ? sprintf('%s=%s', $name, $value) : $name);
        }
    }

    if (is_file($guard)) {
        unlink($guard);
    }

    new Plugin()->finish();
    $unwatched = is_file($guard);
    $plugin->finish();

    expect($plugin->guard())->toBeInstanceOf(Guard::class)
        ->and($plugin->recorder())->toBe(Off::Recording)
        ->and($unwatched)->toBeFalse()
        ->and(json_decode((string) file_get_contents($guard), associative: true))->toMatchArray(['before' => true, 'loaded' => true]);
});

it('records nothing when Pest boots it outside the adapter\'s runs', function (): void {
    $plugin = new Plugin();
    $plugin->boot();

    expect($plugin->recorder())->toBe(Off::Recording);
});

it('records where the adapter asks when Pest boots it', function (): void {
    $mutant = getenv('PEST_MUTATION_TESTING');
    $plugin = new Plugin();

    try {
        putenv('PEST_MUTATION_TESTING');
        putenv('MUTATION_GATE_RESULTS=/r/results.jsonl');
        $plugin->boot();
    } finally {
        putenv('MUTATION_GATE_RESULTS');
        putenv(is_string($mutant) ? sprintf('PEST_MUTATION_TESTING=%s', $mutant) : 'PEST_MUTATION_TESTING');
    }

    expect($plugin->recorder())->toBeInstanceOf(Recorder::class);
});

it('is the Pest plugin this package\'s composer.json lists', function (): void {
    $manifest = json_decode((string) file_get_contents(Tree::at('composer.json')), associative: true);

    expect($manifest)->toHaveKey('extra.pest.plugins', [Plugin::class]);
});
