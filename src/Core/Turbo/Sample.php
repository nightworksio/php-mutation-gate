<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Turbo;

use function array_keys;
use function array_slice;
use function ceil;
use function count;
use function hash;
use function max;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Proof\Key\ContentKeys;

use function uasort;

/**
 * What a run recomputes in PHP to check the helper's answer (ADR-0029): fifty
 * of the paths answered for, or a fiftieth of them where that is more, those
 * whose digest beside the run's base comes first. Which are checked changes
 * with every state of the repository, and nobody picks them.
 */
final readonly class Sample
{
    /** How many paths a run recomputes in PHP at least. */
    private const int AT_LEAST = 50;

    /** The share of the paths a run recomputes in PHP, where that is more than {@see AT_LEAST}. */
    private const float SHARE = 0.02;

    /** @param list<Path> $answered the paths the helper answered for, in the answer's order */
    public static function of(string $base, array $answered): Paths
    {
        $ranks = [];

        foreach ($answered as $at => $path) {
            $ranks[$at] = hash('sha256', ContentKeys::framed($base, $path->value()));
        }

        uasort($ranks, static fn(string $a, string $b): int => $a <=> $b);
        $size = (int) max(self::AT_LEAST, ceil(count($answered) * self::SHARE));
        $sampled = [];

        foreach (array_keys(array_slice($ranks, 0, $size, preserve_keys: true)) as $at) {
            $sampled[] = $answered[$at];
        }

        return Paths::of(...$sampled);
    }
}
