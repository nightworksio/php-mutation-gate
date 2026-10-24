<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report\Digest;

use function array_key_exists;
use function array_slice;
use function count;
use function explode;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Format\Visible;
use NightWorksIO\MutationGate\Core\Mutant\IdPrefix;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;

use function rtrim;
use function sprintf;

/**
 * The digest's detail of one mutant, found by its id or a prefix of six or
 * more as `explain` finds one: where it is, what it came to and the gate's
 * own judgement, its diff, its hint, why, the tests that cover it, the first
 * few by id, those that killed it, its cluster, and how to run it again.
 */
final readonly class MutantDetail
{
    /** How many of the tests that cover a mutant its detail names. */
    private const int TESTS = 5;

    private const string MUTANT = 'mutant %s %s %s %s:%d %s%s';

    private const string DIFF = 'diff';

    /** A line of a mutant's diff, set in below its `diff` line, so no line of it can start one of the digest's. */
    private const string DIFF_LINE = '  %s';

    private const string COVERED = 'covered %d';

    private const string MORE_TESTS = 'more-tests %d';

    /** Where a test a mutant points at would be in the report's list of tests, which holds none there. */
    private const string TEST = 'tests[%d]';

    private const string SEVERAL = '%s names %d mutants of the last run: give more of its id.';

    /**
     * @param list<DigestMutant> $mutants every mutant of the report
     *
     * @throws NotInShape
     */
    public static function of(Node $report, IdPrefix $sought, array $mutants, string $target): string|CannotJudge
    {
        $found = [];

        foreach ($mutants as $mutant) {
            $id = MutantId::parse($mutant->id());

            if ($id instanceof MutantId && $sought->names($id)) {
                $found[] = $mutant;
            }
        }

        return match (count($found)) {
            0 => DigestDetail::nothing($target),
            1 => self::explained($report, $found[0]),
            default => CannotJudge::because(sprintf(self::SEVERAL, Visible::line($target), count($found))),
        };
    }

    /** @throws NotInShape */
    private static function explained(Node $report, DigestMutant $mutant): string
    {
        $tests = $report->field('tests')->items();
        $covered = $mutant->coveredBy();
        $more = count($covered) - self::TESTS;

        return Digest::lines([
            sprintf(
                self::MUTANT,
                Visible::line($mutant->id()),
                $mutant->outcome()->value,
                $mutant->judgement()->value,
                Visible::line($mutant->file()),
                $mutant->line(),
                Visible::line($mutant->mutator()),
                $mutant->changed() ? DigestMutant::CHANGED : '',
            ),
            ...self::diff($mutant->diff()),
            ...self::field('hint', $mutant->hint()),
            ...self::field('reason', $mutant->optional('reason')),
            sprintf(self::COVERED, count($covered)),
            ...self::tests('test', array_slice($covered, 0, self::TESTS), $tests),
            ...$more > 0 ? [sprintf(self::MORE_TESTS, $more)] : [],
            ...self::tests('killed-by', $mutant->killedBy(), $tests),
            ...self::field('cluster', $mutant->optional('cluster')),
            ...self::field('reproduce', $mutant->optional('reproduce')),
        ]);
    }

    /**
     * A line for each of some tests, by its id, opened by a name.
     *
     * @param  list<int>    $places where each is in the report's list of tests
     * @param  list<Node>   $tests  the report's list of tests
     * @return list<string>
     *
     * @throws NotInShape
     */
    private static function tests(string $name, array $places, array $tests): array
    {
        $lines = [];

        foreach ($places as $place) {
            $test = array_key_exists($place, $tests)
                ? $tests[$place]
                : throw NotInShape::missing(sprintf(self::TEST, $place));
            $lines[] = sprintf(Fit::JOINED, $name, Visible::line($test->field('id')->text()));
        }

        return $lines;
    }

    /** @return list<string> a line of a field, opened by its name; none where it says nothing */
    private static function field(string $name, string $said): array
    {
        return $said === '' ? [] : [sprintf(Fit::JOINED, $name, Visible::line($said))];
    }

    /** @return list<string> a diff's `diff` line, then each of its lines set in below it; none for no diff */
    private static function diff(string $diff): array
    {
        if ($diff === '') {
            return [];
        }

        $lines = [self::DIFF];

        foreach (explode("\n", rtrim($diff, "\n")) as $line) {
            $lines[] = sprintf(self::DIFF_LINE, Visible::line($line));
        }

        return $lines;
    }
}
