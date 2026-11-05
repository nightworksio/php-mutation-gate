<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/** `shards.learnedFrom`: which of the shard counts recent runs ran at once a learned `shards.max` takes. */
enum LearnedStatistic: string
{
    /** The most any of them ran at once. */
    case Max = 'max';

    /** The median of what each of them ran at once. */
    case Median = 'median';

    /** The builder's call that sets it. */
    public function php(): string
    {
        return match ($this) {
            self::Max => 'Shards::learnedFromMax()',
            self::Median => 'Shards::learnedFromMedian()',
        };
    }
}
