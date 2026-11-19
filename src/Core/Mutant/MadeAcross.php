<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use function array_map;
use function array_unique;
use function count;
use function max;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;
use function usort;

/**
 * Whether the parts that judge a unit made every line of its files
 * together, each part a file's lines alone or all of them (ADR-0193), so no
 * mutant of the unit was left unmade by every part.
 *
 * @internal
 */
final readonly class MadeAcross
{
    private const string UNMADE = <<<'SAID'
        No shard that judges %s by a part of the test files made the mutants of every line of %s, so none judged some.
        Run every shard of one plan, and the verdict with that plan.
        SAID;

    private const string COUNTED_APART = <<<'SAID'
        The shards that judge %s by parts of the test files count the lines of %s apart,
        so they did not mutate the same code.
        Run every shard of one plan on the commit it was made on.
        SAID;

    /** Why the parts cannot be merged, where they did not make every line of one of the unit's files together. */
    public static function of(Path $unit, MadeLines ...$made): CannotJudge|NotGiven
    {
        foreach (self::listedByAll($unit, ...$made) as $file => $parts) {
            $lasts = array_unique(array_map(static fn(MadeFile $part): int => $part->last()->number(), $parts));

            if (count($lasts) > 1) {
                return CannotJudge::because(sprintf(self::COUNTED_APART, $unit->value(), $file));
            }

            if (! self::cover($parts, $parts[0]->last()->number())) {
                return CannotJudge::because(sprintf(self::UNMADE, $unit->value(), $file));
            }
        }

        return NotGiven::value();
    }

    /**
     * The unit's files every part made on some lines alone, each with every part's account of it, by its path.
     *
     * @return array<string, non-empty-list<MadeFile>>
     */
    private static function listedByAll(Path $unit, MadeLines ...$made): array
    {
        $listed = [];

        foreach ($made as $lines) {
            foreach ($lines as $file) {
                $listed[$file->path()->value()][] = $file;
            }
        }

        $byAll = [];

        foreach ($listed as $file => $parts) {
            $byAll += count($parts) === count($made) && Path::of($file)->within($unit) ? [$file => $parts] : [];
        }

        return $byAll;
    }

    /**
     * Whether the spans these parts made cover every line of a file of this many.
     *
     * @param list<MadeFile> $parts
     */
    private static function cover(array $parts, int $lines): bool
    {
        $spans = [];

        foreach ($parts as $part) {
            foreach ($part as $span) {
                $spans[] = [$span->first()->number(), $span->last()->number()];
            }
        }

        usort($spans, static fn(array $one, array $other): int => $one[0] <=> $other[0]);
        $next = 1;

        foreach ($spans as [$first, $last]) {
            if ($first > $next) {
                return false;
            }

            $next = max($next, $last + 1);
        }

        return $next > $lines;
    }
}
