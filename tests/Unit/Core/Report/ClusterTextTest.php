<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cluster\Cluster;
use NightWorksIO\MutationGate\Core\Report\ClusterText;
use NightWorksIO\MutationGate\Tests\Support\Clustered;

/**
 * The clusters of the clustered verdict: its expression, then its gap.
 *
 * @return list<Cluster>
 */
function clusterTexts(): array
{
    return iterator_to_array(Clustered::verdict()->trees()->clusters(), preserve_keys: false);
}

it('heads a cluster with where its first member is, how many it holds, by which rule, and its id', function (): void {
    [$expression, $gap] = clusterTexts();

    expect(ClusterText::heading($expression))->toBe(sprintf('src/Cart.php:7  3 survivors, one expression  %s', $expression->id()->value()))
        ->and(ClusterText::summary($gap))->toBe('src/Cart.php:16  3 survivors, one gap')
        ->and(ClusterText::size($gap))->toBe('3 survivors, one gap');
});

it('gives why one test may kill them all, then what the tests miss of its first member', function (): void {
    [, $gap] = clusterTexts();

    expect(ClusterText::hint($gap))->toBe(sprintf('%s %s', $gap->kind()->text(), $gap->representative()->hint()->text()));
});

it('writes a block: each member with its diff, the hint, and the stub and explain commands, indented', function (): void {
    [, $gap] = clusterTexts();
    [$log, $events, $cache] = iterator_to_array($gap->members(), preserve_keys: false);

    expect(ClusterText::block($gap))->toBe(implode("\n", [
        ClusterText::heading($gap),
        sprintf('    src/Cart.php:16  MethodCallRemoval  survived, on a changed line  %s', $log->mutant()->id()->value()),
        '    @@ @@',
        '    -        $this->log->write($order);',
        sprintf('    src/Cart.php:17  MethodCallRemoval  survived, on a changed line  %s', $events->mutant()->id()->value()),
        '    @@ @@',
        '    -        $this->events->dispatch($order);',
        sprintf('    src/Cart.php:18  MethodCallRemoval  survived, on a changed line  %s', $cache->mutant()->id()->value()),
        '    @@ @@',
        '    -        $this->cache->forget($order->id);',
        sprintf('    %s', ClusterText::hint($gap)),
        sprintf('    Stub: vendor/bin/mutation-gate stub %s', $gap->id()->value()),
        sprintf('    Explain: vendor/bin/mutation-gate explain %s', $gap->id()->value()),
    ]));
});
