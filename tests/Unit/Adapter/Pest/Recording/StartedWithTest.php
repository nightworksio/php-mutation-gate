<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordLine;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\StartedWith;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('records the arguments after what the results file holds, by the copy the process serves', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    file_put_contents($results, "{\"event\":\"made\"}\n");

    StartedWith::record($results, '/tmp/mutations/abc', ['vendor/bin/pest', '--bail']);

    expect(file_get_contents($results))->toBe(sprintf("{\"event\":\"made\"}\n%s", RecordLine::arguments('/tmp/mutations/abc', ['vendor/bin/pest', '--bail'])));
});

it('records nothing where the adapter names no results file or copy', function (string|false $results, string|false $mutated): void {
    $directory = Scratch::directory();

    StartedWith::record($results === 'here' ? sprintf('%s/results.jsonl', $directory) : $results, $mutated, ['vendor/bin/pest']);

    expect(glob(sprintf('%s/*', $directory)))->toBe([]);
})->with([
    'no results file' => [false, '/tmp/mutations/abc'],
    'an empty results file' => ['', '/tmp/mutations/abc'],
    'no copy' => ['here', false],
    'an empty copy' => ['here', ''],
]);
