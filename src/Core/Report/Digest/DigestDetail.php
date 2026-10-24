<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report\Digest;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cluster\ClusterId;
use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Format\Visible;
use NightWorksIO\MutationGate\Core\Mutant\IdPrefix;

use function sprintf;

/**
 * What `--detail` asks the digest for, one target a call: the legend; a
 * file (see FileDetail); a mutant, by its id or a prefix of six or more as
 * `explain` finds one (see MutantDetail); or a cluster of survivors, by its
 * id (see ClusterDetail).
 */
final readonly class DigestDetail
{
    /** What `--detail` takes for the legend. */
    public const string LEGEND = 'legend';

    /** Why a target names nothing the last run holds. */
    public const string NOTHING = <<<'TEXT'
        %s is no file, mutant or cluster of the last run: give a path, a mutant id of 6 or more characters, a
        cluster id or legend.
        TEXT;

    /** @throws NotInShape */
    public static function of(Node $report, string $target): string|CannotJudge
    {
        if ($target === self::LEGEND) {
            return Digest::legend();
        }

        $mutants = self::mutants($report);
        $files = [];

        foreach ($mutants as $mutant) {
            $files[$mutant->file()] = true;
        }

        $sought = ClusterId::orMutant($target);

        return match (true) {
            array_key_exists($target, $files) => FileDetail::of($report, $target, $mutants),
            $sought instanceof ClusterId => ClusterDetail::of($report, $sought, $mutants),
            $sought instanceof IdPrefix => MutantDetail::of($report, $sought, $mutants, $target),
            default => self::nothing($target),
        };
    }

    /** Why a target names nothing the last run holds. */
    public static function nothing(string $target): CannotJudge
    {
        return CannotJudge::because(Fit::plain(sprintf(self::NOTHING, Visible::line($target))));
    }

    /**
     * @return list<DigestMutant>
     *
     * @throws NotInShape
     */
    private static function mutants(Node $report): array
    {
        $mutants = [];

        foreach ($report->field('mutants')->items() as $entry) {
            $mutants[] = DigestMutant::of($entry);
        }

        return $mutants;
    }
}
