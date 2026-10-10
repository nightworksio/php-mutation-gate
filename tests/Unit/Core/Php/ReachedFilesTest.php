<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Php\NamedFiles;
use NightWorksIO\MutationGate\Core\Php\ReachedFiles;

/**
 * A graph whose files each mention the name the next declares, as edges.
 *
 * @param  array<string, list<string>> $edges
 * @return array{NamedFiles, ReachedFiles}
 */
function reachedGraph(array $edges): array
{
    $declares = [];
    $mentions = [];

    foreach ($edges as $file => $targets) {
        $declares[$file] = [sprintf('N\\%s', $file)];
        $mentions[$file] = array_map(static fn(string $target): string => sprintf('N\\%s', $target), $targets);

        foreach ($targets as $target) {
            $declares[$target] ??= [sprintf('N\\%s', $target)];
        }
    }

    $named = NamedFiles::byName($declares, $mentions);

    return [$named, $named->reaches()];
}

/**
 * What a walk reaches from these files, sorted.
 *
 * @return list<string>
 */
function walkedSorted(NamedFiles $named, string ...$from): array
{
    $walked = $named->reachedFrom(...$from);
    sort($walked, SORT_STRING);

    return $walked;
}

/** @return array<string, list<string>> a chain of files, each naming the next, written last first */
function reachedChain(int $length): array
{
    $edges = [];

    for ($at = $length - 1; $at > 0; $at--) {
        $edges[sprintf('f%02d', $at - 1)] = [sprintf('f%02d', $at)];
    }

    return $edges;
}

it('reaches what a walk reaches, sorted, from any set of files', function (array $edges, array $from): void {
    /** @var array<string, list<string>> $edges */
    /** @var list<string> $from */
    [$named, $reached] = reachedGraph($edges);

    expect($reached->from(...$from))->toBe(walkedSorted($named, ...$from));
})->with([
    'a chain' => [['a' => ['b'], 'b' => ['c'], 'c' => []], ['a']],
    'the middle of a chain' => [['a' => ['b'], 'b' => ['c'], 'c' => []], ['b']],
    'a cycle' => [['a' => ['b'], 'b' => ['c'], 'c' => ['a'], 'd' => ['a']], ['b']],
    'a file that names itself' => [['a' => ['a', 'b'], 'b' => []], ['a']],
    'a diamond' => [['a' => ['c', 'b'], 'b' => ['d'], 'c' => ['d'], 'd' => []], ['a']],
    'two starts that share what they reach' => [['a' => ['c'], 'b' => ['c'], 'c' => ['d'], 'd' => []], ['b', 'a']],
    'a file only named' => [['a' => ['b']], ['b']],
    'no start' => [['a' => ['b']], []],
    'a chain across many bytes, written last first' => [fn(): array => reachedChain(30), ['f00']],
    'the tail of a long chain' => [fn(): array => reachedChain(30), ['f27']],
]);

it('reaches a file the graph does not hold as itself alone, in sorted place among the rest', function (): void {
    [, $reached] = reachedGraph(['b' => ['d'], 'd' => []]);

    expect($reached->from('c', 'b', 'a'))->toBe(['a', 'b', 'c', 'd'])
        ->and($reached->from('z'))->toBe(['z'])
        ->and($reached->files())->toBe(['b', 'd']);
});

it('sorts byte by byte, so paths that read as numbers keep one order in the whole graph and in each reach', function (): void {
    [, $reached] = reachedGraph(['a' => ['9.0', '1e1'], 'b' => []]);

    expect($reached->files())->toBe(['1e1', '9.0', 'a', 'b'])
        ->and($reached->from('a'))->toBe(['1e1', '9.0', 'a'])
        ->and($reached->from('b', '30', '1e2'))->toBe(['1e2', '30', 'b']);
});

it('reaches nothing from nothing in an empty graph', function (): void {
    expect(NamedFiles::byName([], [])->reaches()->from())->toBe([]);
});
