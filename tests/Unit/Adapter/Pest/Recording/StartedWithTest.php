<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Recording\KillerFile;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordLine;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\StartedWith;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('writes the arguments to the process\'s killer file, which Pest\'s own process takes as their record', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    file_put_contents($results, "{\"event\":\"made\"}\n");

    StartedWith::record($results, '/tmp/mutations/abc', ['vendor/bin/pest', '--filter=a b|c']);

    expect(file_get_contents($results))->toBe("{\"event\":\"made\"}\n")
        ->and(KillerFile::taken(KillerFile::beside($results, '/tmp/mutations/abc'), '/tmp/mutations/abc'))
        ->toBe([RecordLine::arguments('/tmp/mutations/abc', ['vendor/bin/pest', '--filter=a b|c'])]);
});

it('takes no arguments from a line cut short, or from one whose JSON is no list of words', function (string $line): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    file_put_contents(KillerFile::beside($results, '/tmp/mutations/abc'), $line);

    expect(KillerFile::taken(KillerFile::beside($results, '/tmp/mutations/abc'), '/tmp/mutations/abc'))->toBe([]);
})->with([
    'cut short' => ['arguments %5B%22vendor'],
    'not JSON' => ["arguments %5B%22vendor\n"],
    'not a list' => [fn(): string => sprintf("arguments %s\n", rawurlencode('{"a": "b"}'))],
    'not words' => [fn(): string => sprintf("arguments %s\n", rawurlencode('["a", 1]'))],
]);

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
