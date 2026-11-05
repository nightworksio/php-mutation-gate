<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator\Engine\Schema;

use function array_slice;
use function count;

use NightWorksIO\MutationGate\Core\Format\Bytes;

use function sprintf;

/**
 * How a file's code is spliced with its sites (see Spliced): from one offset
 * to another, each site within it switched, and the ids of those switched.
 *
 * @internal the engine's own
 */
final readonly class Splicing
{
    private function __construct(private string $source, private string $switch)
    {
    }

    public static function of(string $source, string $switch): self
    {
        return new self($source, $switch);
    }

    /**
     * The code from one offset to another with these sites, each within it,
     * switched, and the ids of those switched.
     *
     * @param  list<Placed>              $sites sorted by where they start, the longest first
     * @return array{string, list<int>}
     */
    public function within(int $from, int $to, array $sites): array
    {
        $text = '';
        $switched = [];
        $at = $from;
        $next = 0;

        while ($next < count($sites)) {
            $site = $sites[$next];
            $inside = $this->inside($site, array_slice($sites, $next + 1));
            $next += 1 + count($inside);
            [$spliced, $ids] = $this->site($site, $inside);
            $text = sprintf('%s%s%s', $text, Bytes::slice($this->source, $at, $site->start() - $at), $spliced);
            $switched = [...$switched, ...$ids];
            $at = $site->end();
        }

        return [sprintf('%s%s', $text, Bytes::slice($this->source, $at, $to - $at)), $switched];
    }

    /**
     * The sites after one that stand within it.
     *
     * @param  list<Placed> $after
     * @return list<Placed>
     */
    private function inside(Placed $site, array $after): array
    {
        $count = 0;

        while ($count < count($after) && $after[$count]->isWithin($site)) {
            $count++;
        }

        return array_slice($after, 0, $count);
    }

    /**
     * A site switched, the sites within it, a site of the same node among
     * them, switched in its original, and the ids of all switched.
     *
     * @param  list<Placed>              $inside
     * @return array{string, list<int>}
     */
    private function site(Placed $site, array $inside): array
    {
        [$switched, $ids] = $this->within($site->start(), $site->end(), $inside);

        return [$site->switched($this->switch, $switched), [...$ids, $site->id()]];
    }
}
