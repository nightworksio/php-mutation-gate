<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamArguments;

it('keeps a run\'s arguments but those Pest\'s own process alone takes, wherever they stand', function (): void {
    expect(StreamArguments::ofRun([
        '--coverage-php=map.cov',
        '--colors=never',
        '--mutate',
        '--mutate-id=7',
        'tests/--mutate',
        '--parallel',
        '--parallel-processes=4',
        'not--parallel',
        '--no-coverage',
        'run--coverage',
    ]))->toBe(['--colors=never', 'tests/--mutate', 'not--parallel', '--no-coverage', 'run--coverage'])
        ->and(StreamArguments::ofRun([]))->toBe([]);
});

it('loads the extension the gate names into a stream, and none where it names none', function (): void {
    expect(StreamArguments::loading('/opt/outbreak.so'))->toBe(['-d', 'extension=/opt/outbreak.so'])
        ->and(StreamArguments::loading(''))->toBe([])
        ->and(StreamArguments::loading(extension: false))->toBe([]);
});
