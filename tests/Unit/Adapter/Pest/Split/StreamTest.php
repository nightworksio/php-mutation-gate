<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\Stream;
use NightWorksIO\MutationGate\Tests\Support\FreshProcess;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * The lines a stream started from a plan wrote, each decoded, its seconds left out.
 *
 * @param  array<string, mixed> $plan
 * @param  Closure(): void      $run
 * @return list<array<mixed>>
 */
function streamedLines(array $plan, Closure $run): array
{
    $root = Scratch::directory();
    $results = sprintf('%s/results.jsonl', $root);
    Scratch::write($root, 'plan.json', (string) json_encode(['results' => $results, ...$plan]));
    $started = Stream::from(sprintf('%s/plan.json', $root));

    try {
        $run();
    } finally {
        Stream::finished();
    }

    $lines = [];

    $written = is_file($results) ? file($results, FILE_IGNORE_NEW_LINES) : [];

    foreach (is_array($written) ? $written : [] as $line) {
        $read = json_decode($line, associative: true);

        if (is_array($read)) {
            unset($read['s']);
            $lines[] = $read;
        }
    }

    return $started ? $lines : [['not started']];
}

it('writes how the parent came out of each test it ran', function (): void {
    $root = Scratch::directory();
    $results = sprintf('%s/results.jsonl', $root);
    Scratch::write($root, 'plan.json', (string) json_encode(['results' => $results, 'sites' => []]));

    expect(FreshProcess::answer(sprintf(<<<'PHP'
        use NightWorksIO\MutationGate\Adapter\Pest\Split\Stream;

        $started = Stream::from(%s);
        Stream::began('T::passes');
        Stream::ended();
        Stream::began('T::fails');
        Stream::failed();
        Stream::ended();
        Stream::ended();
        Stream::finished();
        $lines = [];

        foreach (is_file(%s) ? file(%s, FILE_IGNORE_NEW_LINES) : [] as $line) {
            $read = json_decode($line, associative: true);
            unset($read['s']);
            $lines[] = $read;
        }

        echo json_encode($started ? $lines : [['not started']]);
        PHP, var_export(sprintf('%s/plan.json', $root), return: true), var_export($results, return: true), var_export($results, return: true))))->json()->toBe([
        ['k' => 'parent', 't' => 'T::passes', 'p' => true],
        ['k' => 'parent', 't' => 'T::fails', 'p' => false],
    ]);
})->skip(fn(): bool => ! function_exists('pcntl_fork') || ! function_exists('posix_kill'), 'a split stream needs a PHP that forks');

it('falls back for a test that runs in a process of its own, or holds a socket the stream did not as it started', function (): void {
    $pair = [];

    expect(streamedLines(['sites' => []], static function () use (&$pair): void {
        Stream::began('T::isolated');
        Stream::isolated();
        Stream::isolated();
        Stream::ended();
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        Stream::began('T::holds');
        Stream::ended();
    }))->toBe([
        ['k' => 'fallback', 't' => 'T::isolated'],
        ['k' => 'parent', 't' => 'T::isolated', 'p' => true],
        ['k' => 'fallback', 't' => 'T::holds'],
        ['k' => 'parent', 't' => 'T::holds', 'p' => true],
    ]);

    foreach (is_array($pair) ? $pair : [] as $end) {
        fclose($end);
    }
})->skip(fn(): bool => ! function_exists('pcntl_fork') || ! function_exists('posix_kill'), 'a split stream needs a PHP that forks');

it('leaves for its own run each mutant of a file the process loaded before its schema could be served', function (): void {
    expect(streamedLines([
        'sites' => ['f1' => [1 => ['m1', '', []]], 'f2' => [1 => ['m2', '', []]]],
        'paths' => ['f1' => __FILE__, 'f2' => '/nowhere/Unloaded.php'],
    ], static function (): void {
    }))->toBe([['k' => 'unserved', 'm' => 'm1']]);
})->skip(fn(): bool => ! function_exists('pcntl_fork') || ! function_exists('posix_kill'), 'a split stream needs a PHP that forks');

it('starts no stream from a file that holds no plan', function (): void {
    expect(Stream::from('/nowhere/plan.json'))->toBeFalse();
});
