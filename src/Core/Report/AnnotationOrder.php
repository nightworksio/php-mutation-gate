<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_filter;

use Closure;
use NightWorksIO\MutationGate\Core\Cluster\Cluster;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;

/**
 * The mutants a verdict counts as not killed, as line annotations rank them (ADR-0009): those in a set that failed,
 * then the rest; within each, those on a changed line first, then security mutants (ADR-0021), each part in the
 * order it had. A cluster of survivors is one item, ranked where any of its members is (ADR-0022).
 */
final readonly class AnnotationOrder
{
    /**
     * @param list<JudgedMutant|Cluster> $failing
     * @param list<JudgedMutant|Cluster> $other
     */
    private function __construct(private array $failing, private array $other)
    {
    }

    public static function of(Verdict $verdict): self
    {
        $overview = Overview::of($verdict);
        $secured = self::forward(
            Folded::of($overview->survivors(), $verdict->trees()->clusters()),
            static fn(JudgedMutant|Cluster $item): bool => self::isAny($item, $overview->isSecurity(...)),
        );
        $ranked = self::forward($secured, static fn(JudgedMutant|Cluster $item): bool => $item->isOnChangedLine());
        $failing = [];
        $other = [];

        foreach ($ranked as $item) {
            $isFailing = self::isAny($item, $overview->isFailing(...));
            $failing = $isFailing ? [...$failing, $item] : $failing;
            $other = $isFailing ? $other : [...$other, $item];
        }

        return new self($failing, $other);
    }

    /**
     * Those in a set that failed, ranked.
     *
     * @return list<JudgedMutant|Cluster>
     */
    public function failing(): array
    {
        return $this->failing;
    }

    /**
     * The rest, ranked.
     *
     * @return list<JudgedMutant|Cluster>
     */
    public function other(): array
    {
        return $this->other;
    }

    /**
     * These mutants and clusters, those that are so first, each part in the order it had.
     *
     * @param  list<JudgedMutant|Cluster>          $items
     * @param  Closure(JudgedMutant|Cluster): bool $is
     * @return list<JudgedMutant|Cluster>
     */
    private static function forward(array $items, Closure $is): array
    {
        return [
            ...array_filter($items, $is),
            ...array_filter($items, static fn(JudgedMutant|Cluster $item): bool => ! $is($item)),
        ];
    }

    /**
     * Whether a mutant, or any member of a cluster, is so.
     *
     * @param Closure(JudgedMutant): bool $is
     */
    private static function isAny(JudgedMutant|Cluster $item, Closure $is): bool
    {
        foreach ($item instanceof Cluster ? $item->members() : [$item] as $mutant) {
            if ($is($mutant)) {
                return true;
            }
        }

        return false;
    }
}
