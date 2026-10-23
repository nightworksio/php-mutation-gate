<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Sample;

use function count;

use NightWorksIO\MutationGate\Core\Score\Percentage;

use function sprintf;

/**
 * What a plan says of the sample it drew (ADR-0020): how
 * many of the units it could sample it runs, from which seed and for which
 * margin and confidence; or, where no unit could be sampled, that the run
 * judges every tree whole. A plan that drew no sample says nothing.
 */
final readonly class SampleText
{
    private const string DRAWN = <<<'SAID'
        Sampled %d of the %d units no run knows exactly, drawn from %s, for %s points either side at %d%% confidence.
        SAID;

    private const string NOTHING = <<<'SAID'
        --sample has nothing to sample: every tree is held to 100, or each unit it runs is known exactly.
        SAID;

    /** @return list<string> */
    public static function planned(Sample $sample): array
    {
        $sampled = count($sample->sampled());
        $units = $sampled + count($sample->unsampled());

        return match (true) {
            ! $sample->isDrawn() => [],
            $units === 0 => [self::NOTHING],
            default => [sprintf(
                self::DRAWN,
                $sampled,
                $units,
                $sample->seed(),
                Percentage::points(Percentage::hundredthsOf($sample->margin())),
                $sample->confidence()->value,
            )],
        };
    }
}
