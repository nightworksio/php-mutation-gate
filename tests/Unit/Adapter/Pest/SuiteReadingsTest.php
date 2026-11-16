<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\FileReading;
use NightWorksIO\MutationGate\Adapter\Pest\SuiteReadings;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

const SUITE_READING_TEXT = "<?php\nnamespace R;\nfinal class Kept {}\n";

/**
 * The results file of a run in a new directory, and the file its suite's readings are kept in beside it.
 *
 * @return array{string, string}
 */
function suiteReadingsResults(): array
{
    $results = sprintf('%s/results.jsonl', Scratch::directory());

    return [$results, sprintf('%s.suite-readings.json', $results)];
}

/**
 * The kept readings, each file's declared names changed to this, as a reading kept from another text would be.
 */
function suiteReadingsMarked(string $kept, string $declares, string $code = ''): void
{
    $decoded = json_decode((string) file_get_contents($kept), associative: true);
    $read = is_array($decoded) ? $decoded : [];
    $files = [];

    foreach (is_array($read['files'] ?? null) ? $read['files'] : [] as $file => $reading) {
        $files[$file] = [...(is_array($reading) ? $reading : []), 'declares' => [$declares]];
    }

    file_put_contents($kept, (string) json_encode([...$read, 'files' => $files, ...($code === '' ? [] : ['code' => $code])]));
}

it('keeps the readings beside the results file, and takes a file\'s kept reading while its text is the one it was read from', function (): void {
    [$results, $kept] = suiteReadingsResults();

    $first = SuiteReadings::beside($results)->of(['/t/KeptTest.php' => SUITE_READING_TEXT]);
    suiteReadingsMarked($kept, 'r\marked');
    $again = SuiteReadings::beside($results)->of(['/t/KeptTest.php' => SUITE_READING_TEXT]);
    $changed = SuiteReadings::beside($results)->of(['/t/KeptTest.php' => sprintf('%s// changed', SUITE_READING_TEXT)]);

    expect($first['/t/KeptTest.php']->declares)->toBe(['r\kept'])
        ->and($first['/t/KeptTest.php'])->toEqual(FileReading::of(SUITE_READING_TEXT))
        ->and($again['/t/KeptTest.php']->declares)->toBe(['r\marked'])
        ->and($changed['/t/KeptTest.php']->declares)->toBe(['r\kept']);
});

it('reads every file again where the readings were kept by another build of the gate, or cannot be read as kept', function (Closure $spoilt): void {
    [$results, $kept] = suiteReadingsResults();
    SuiteReadings::beside($results)->of(['/t/KeptTest.php' => SUITE_READING_TEXT]);
    $spoilt($kept);

    $read = SuiteReadings::beside($results)->of(['/t/KeptTest.php' => SUITE_READING_TEXT]);
    suiteReadingsMarked($kept, 'r\again');

    expect($read['/t/KeptTest.php']->declares)->toBe(['r\kept'])
        ->and(SuiteReadings::beside($results)->of(['/t/KeptTest.php' => SUITE_READING_TEXT])['/t/KeptTest.php']->declares)->toBe(['r\again']);
})->with([
    'by another build' => [static fn(string $kept) => suiteReadingsMarked($kept, 'r\\marked', code: 'another build')],
    'in another shape' => [static fn(string $kept): int|false => file_put_contents($kept, '{"code": 1}')],
    'not JSON' => [static fn(string $kept): int|false => file_put_contents($kept, 'not JSON')],
]);

it('leaves nothing beside the results where the readings cannot be moved into place, and reads every file all the same', function (): void {
    [$results, $kept] = suiteReadingsResults();
    mkdir($kept);
    // A move that fails warns, as PHP does, and keeps nothing.
    set_error_handler(static fn(): bool => true);

    try {
        $read = SuiteReadings::beside($results)->of(['/t/KeptTest.php' => SUITE_READING_TEXT]);
    } finally {
        restore_error_handler();
    }

    expect($read['/t/KeptTest.php']->declares)->toBe(['r\\kept'])
        ->and(glob(sprintf('%s*', $results)))->toBe([$kept]);
});

it('keeps no reading where the gate names no results file', function (string|false $results): void {
    $here = (string) getcwd();
    chdir(Scratch::directory());

    try {
        $read = SuiteReadings::beside($results)->of(['/t/KeptTest.php' => SUITE_READING_TEXT]);
        $listed = scandir('.');
        $left = is_array($listed) ? array_values(array_diff($listed, ['.', '..'])) : $listed;
    } finally {
        chdir($here);
    }

    expect($read['/t/KeptTest.php']->declares)->toBe(['r\kept'])
        ->and($left)->toBe([]);
})->with([
    'unset' => [false],
    'empty' => [''],
]);
