<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report\Digest;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Format\Visible;
use NightWorksIO\MutationGate\Core\Report\Outcome;
use NightWorksIO\MutationGate\Core\Report\Term;
use NightWorksIO\MutationGate\Core\Verdict\Origin;

use function sprintf;

/**
 * The digest's detail of one file: its counts by outcome, each graded, then
 * every mutant of it not killed, as the overview lists a survivor; the
 * killed are counted alone. A file of a unit this run did not judge, whose
 * result was carried, is said to be and holds its counts alone.
 *
 * @internal
 */
final readonly class FileDetail
{
    private const string FILE = 'file %s %s';

    /**
     * @param list<DigestMutant> $mutants every mutant of the report
     *
     * @throws NotInShape
     */
    public static function of(Node $report, string $path, array $mutants): string
    {
        $mine = [];
        $judged = [];

        foreach ($mutants as $mutant) {
            if ($mutant->file() === $path) {
                $mine[] = $mutant;
                $judged = self::counted($judged, $mutant->judgement()->value);
            }
        }

        $carried = self::carried($report, $path);
        $lines = [sprintf(self::FILE, Visible::line($path), Digest::outcomes($judged))];

        return Digest::lines($carried ? [...$lines, Term::Carried->value] : [...$lines, ...self::unkilled($mine)]);
    }

    /**
     * @param  array<string, int> $judged
     * @return array<string, int>
     */
    private static function counted(array $judged, string $judgement): array
    {
        $judged[$judgement] = array_key_exists($judgement, $judged) ? $judged[$judgement] + 1 : 1;

        return $judged;
    }

    /**
     * @param  list<DigestMutant> $mutants
     * @return list<string>
     *
     * @throws NotInShape
     */
    private static function unkilled(array $mutants): array
    {
        $lines = [];

        foreach ($mutants as $mutant) {
            if ($mutant->outcome() !== Outcome::Killed) {
                $lines[] = $mutant->said();
            }
        }

        return $lines;
    }

    /**
     * Whether this run did not judge a file's unit, whose result a proof or an earlier run carried.
     *
     * @throws NotInShape
     */
    private static function carried(Node $report, string $path): bool
    {
        foreach ($report->field('trees')->items() as $tree) {
            foreach ($tree->field('units')->items() as $unit) {
                if ($unit->field('path')->text() === $path) {
                    return $unit->field('origin')->text() !== Origin::Run->value;
                }
            }
        }

        return false;
    }
}
