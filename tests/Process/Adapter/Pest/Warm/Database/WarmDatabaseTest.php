<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Pool;
use NightWorksIO\MutationGate\Tests\Support\GateEnvironment;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use Symfony\Component\Process\Process;

$holds = [
    'holds:src/Adapter/Pest/Warm/Database/WarmDatabase.php',
    'holds:src/Adapter/Pest/Warm/Database/Laravel.php',
    'holds:src/Adapter/Pest/Warm/Database/LaravelConfig.php',
    'holds:src/Adapter/Pest/Warm/Database/Baseline.php',
    'holds:src/Adapter/Pest/Warm/Database/Snapshot.php',
];

beforeEach(function (): void {
    GateEnvironment::clear();
});

afterEach(function (): void {
    GateEnvironment::restore();
    Scratch::sweep();
});

/**
 * A run of the suite whose one test uses RefreshDatabase through place 1's
 * warm worker of this directory, on a database in it, its test committing
 * its row where it dirties the database: what it printed.
 */
function refreshed(string $directory, bool $dirties): string
{
    $run = refreshing($directory, $dirties ? ['FIXTURE_DIRTY' => '1'] : []);
    $run->mustRun();

    return $run->getOutput();
}

/**
 * That run, with these variables too, not yet started.
 *
 * @param array<string, string> $variables
 */
function refreshing(string $directory, array $variables): Process
{
    putenv(sprintf('%s=%s/warm', GateVariable::Warm->value, $directory));

    return new Process(
        [...Pool::prefix(1), Tree::at('tests/Fixtures/Laravel/refresh-database')],
        env: ['FIXTURE_DATABASE' => sprintf('%s/testing.sqlite', $directory), ...$variables],
    );
}

/** The database as a run's test found it. */
function foundBy(string $printed): string
{
    return preg_match('/^found (.+)$/m', $printed, $found) === 1 ? $found[1] : '';
}

it('starts each child on a database alike to the one freshly migrated, migrating nothing', function (): void {
    $directory = Scratch::directory();
    $fresh = refreshed($directory, dirties: false);
    $warm = refreshed($directory, dirties: false);

    expect($fresh)->toStartWith("migrated\n")
        ->and($warm)->not->toContain('migrated')
        ->and(foundBy($warm))->toBe(foundBy($fresh))
        ->and(foundBy($warm))->not->toBe('')
        ->and($warm)->toEndWith("made 3\n")
        ->and($fresh)->toEndWith("made 3\n");
})->group(...$holds);

it('migrates again in the child after one whose test dirtied the database, so nothing it left reaches the next mutant', function (): void {
    $directory = Scratch::directory();
    $fresh = refreshed($directory, dirties: false);
    $dirtying = refreshed($directory, dirties: true);
    $after = refreshed($directory, dirties: false);

    expect($dirtying)->not->toContain('migrated')
        ->and($after)->toStartWith("migrated\n")
        ->and(foundBy($after))->toBe(foundBy($fresh))
        ->and($after)->toEndWith("made 3\n");
})->group(...$holds);

it('starts the child after one stopped at its limit on the baseline that one took, migrating nothing', function (): void {
    $directory = Scratch::directory();
    $stopped = refreshing($directory, ['FIXTURE_HANG' => '1']);
    $stopped->start();
    $stopped->waitUntil(static fn(string $type, string $printed): bool => str_contains($printed, 'made'));
    $stopped->stop(0);
    $next = refreshed($directory, dirties: false);

    expect($stopped->getOutput())->toStartWith("migrated\n")
        ->and($next)->not->toContain('migrated')
        ->and(foundBy($next))->toBe(foundBy($stopped->getOutput()))
        ->and($next)->toEndWith("made 3\n");
})->group(...$holds);

it('takes no baseline where a connection is inside a transaction as RefreshDatabase begins its first', function (): void {
    $directory = Scratch::directory();
    $open = refreshing($directory, ['FIXTURE_OPEN' => '1']);
    $open->mustRun();
    $next = refreshed($directory, dirties: false);

    expect($open->getOutput())->toStartWith("migrated\n")
        ->and($next)->toStartWith("migrated\n");
})->group(...$holds);
