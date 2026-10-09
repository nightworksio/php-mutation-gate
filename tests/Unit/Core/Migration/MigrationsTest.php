<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Format\JsonDocument;
use NightWorksIO\MutationGate\Core\Migration\Migration;
use NightWorksIO\MutationGate\Core\Migration\Migrations;
use NightWorksIO\MutationGate\Core\Migration\Remove;
use NightWorksIO\MutationGate\Core\Migration\Rename;
use NightWorksIO\MutationGate\Core\Migration\Retired;
use NightWorksIO\MutationGate\Core\NotGiven;

function migrationsDocument(string $json): JsonDocument
{
    $document = JsonDocument::parse($json);

    return $document instanceof JsonDocument ? $document : throw new LogicException('Not JSON.');
}

/** Two releases' changes: a rename in 2.0.0, and in 3.0.0 the renamed key's rename and a removal. */
function migrationReleases(): Migrations
{
    return Migrations::of(
        Migration::in('2.0.0', Rename::of('runnr', 'runer')),
        Migration::in('3.0.0', Rename::of('runer', 'runner'), Remove::of('legacy', 'nothing reads it')),
    );
}

it('makes each release\'s changes in turn, the oldest first, so a file several releases behind moves all the way', function (): void {
    expect(migrationReleases()->applied(migrationsDocument('{"runnr": "pest", "legacy": 1}'))->text())->toBe('{"runner": "pest"}')
        ->and(array_map(static fn(Retired $retired): string => $retired->pending(), [...migrationReleases()]))->toBe([
            '`runnr` became `runer` in 2.0.0: run `mutation-gate migrate`',
            '`runer` became `runner` in 3.0.0: run `mutation-gate migrate`',
            '`legacy` was removed (nothing reads it) in 3.0.0: run `mutation-gate migrate`',
        ]);
});

it('names each change a file still needs at what it retired, and nothing where it needs none', function (): void {
    expect(migrationReleases()->pending(migrationsDocument('{"runnr": "pest", "legacy": 1}')))->toEqual(Invalid::because(
        Problem::at('runnr', '`runnr` became `runer` in 2.0.0: run `mutation-gate migrate`'),
        Problem::at('legacy', '`legacy` was removed (nothing reads it) in 3.0.0: run `mutation-gate migrate`'),
    ))
        ->and(migrationReleases()->pending(migrationsDocument('{"runner": "pest"}')))->toEqual(NotGiven::value());
});

it('leaves for a hand edit each change a migrated file still needs', function (): void {
    $both = migrationReleases()->applied(migrationsDocument('{"runer": "pest", "runner": "phpunit"}'));

    expect(migrationReleases()->left($both))->toEqual(Invalid::because(Problem::at(
        'runer',
        '`runer` became `runner` in 3.0.0, and migrate cannot make that change here: edit it by hand',
    )))
        ->and(migrationReleases()->left(migrationsDocument('{"runner": "pest"}')))->toEqual(NotGiven::value());
});

it('holds no change of the gate\'s own before a release first retires a key or a baseline format', function (): void {
    expect([...Migrations::config()])->toBe([])
        ->and([...Migrations::baseline()])->toBe([]);
});
