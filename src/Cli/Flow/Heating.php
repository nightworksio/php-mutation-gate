<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_key_exists;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Core\Config\HeatmapRows;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Heat\FunctionSpans;
use NightWorksIO\MutationGate\Core\Heat\Heatmap;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Proofs;
use NightWorksIO\MutationGate\Core\Verdict\Judge;
use NightWorksIO\MutationGate\Core\Verdict\Origin;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\UnitResult;
use NightWorksIO\MutationGate\Core\Verdict\UnitResults;

/**
 * The heatmap of a verdict (ADR-0084): every file it judged, each mutant
 * with the function of the file on disk its line is in, compared with the
 * same units judged over the default branch's newest proof of each, each
 * of their mutants with the function its proof names for its line. A unit
 * the default branch never proved has no change against the base, and a
 * proof that names no function gives its functions none. It lists as many
 * files as the config's `heatmap` keys say.
 *
 * @internal
 */
final readonly class Heating
{
    public function __construct(private Directory $project, private HeatmapRows $rows)
    {
    }

    /** The heatmap of these verdicts, over these units, against the base the judge makes of these proofs. */
    public function of(TreeVerdicts $verdicts, UnitResults $judged, Judge $judge, Proofs $defaultBranch): Heatmap
    {
        $newest = $defaultBranch->newest();
        $results = [];
        $spans = [];

        foreach ($judged as $result) {
            $path = $result->unit()->path();
            $proof = $newest->of($path);

            if ($proof instanceof Proof) {
                $results[] = UnitResult::fromProof($result->unit(), Origin::Carried, $proof);
                $spans[$path->value()] = $proof->functions();
            }
        }

        $base = Heatmap::of(
            $judge->trees(UnitResults::of(...$results)),
            fn(Path $file): FunctionSpans => $this->rows->methods() && array_key_exists($file->value(), $spans)
                ? $spans[$file->value()]
                : FunctionSpans::none(),
        );

        return Heatmap::of($verdicts, $this->onDisk(...))
            ->comparedWith($base)
            ->listing($this->rows);
    }

    /** The functions of a file as it reads now; none where it cannot be read, or functions are not scored. */
    private function onDisk(Path $file): FunctionSpans
    {
        $contents = $this->rows->methods() ? $this->project->read($file) : null;

        return $contents instanceof Contents ? FunctionSpans::in($contents) : FunctionSpans::none();
    }
}
