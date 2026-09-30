<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Plugin;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Off;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Tests\Support\Tree;

it('records nothing until Pest boots it', function (): void {
    expect(new Plugin()->recorder())->toBe(Off::Recording);
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
