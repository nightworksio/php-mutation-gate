<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report\Digest;

use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Format\Visible;
use NightWorksIO\MutationGate\Core\Report\Percent;
use NightWorksIO\MutationGate\Core\Score\Percentage;

use function sprintf;

/**
 * The digest's scores: a percentage as every report prints one, and a line
 * for each set, a tree by its path and a new-code or security set by its
 * package, with its score, its floor and its judgement.
 *
 * @internal
 */
final readonly class Scores
{
    private const string SET = 'set %s %s score=%s floor=%s verdict=%s';

    /** What the digest calls each list of sets in the report, by the report's key. */
    private const array KINDS = ['trees' => 'tree', 'newCode' => 'new-code', 'security' => 'security'];

    /**
     * A percentage the report holds; a dash where it holds none.
     *
     * @throws NotInShape
     */
    public static function percent(Node $percent): string
    {
        return $percent->isPresent()
            ? Percent::ofHundredths(Percentage::hundredthsOf($percent->number()))
            : Digest::NONE;
    }

    /**
     * @return list<string>
     *
     * @throws NotInShape
     */
    public static function sets(Node $report): array
    {
        $lines = [];

        foreach (self::KINDS as $key => $kind) {
            foreach ($report->field($key)->items() as $set) {
                $path = $set->field('path');
                $lines[] = sprintf(
                    self::SET,
                    $kind,
                    Visible::line($path->isPresent() ? $path->text() : $set->field('package')->text()),
                    self::percent($set->field('score')),
                    self::percent($set->field('floor')),
                    Visible::line($set->field('judgement')->text()),
                );
            }
        }

        return $lines;
    }
}
