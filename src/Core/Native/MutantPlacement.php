<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Native;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\OwnArchive;

/**
 * Where the gate's wrapper serves a mutant in a project (ADR-0023): the
 * override PHP prepends before Composer's autoloader, each mutant's own
 * files, and the files of a run beside them (ADR-0148).
 *
 * @internal
 */
interface MutantPlacement
{
    /** The override, written for the archive the gate runs from; or why PHP cannot be started with it. */
    public function override(OwnArchive|NotGiven $archive): string|CannotJudge;

    /**
     * PHP's own options of every run of a mutant served by this override.
     *
     * @return list<string>
     */
    public function startingPhp(string $override): array;

    /** The files of a mutant whose mutated file holds this code, for a run of the tests this selection lists. */
    public function placed(Mutant $mutant, Contents $code, Selection $selection): PlacedMutant|CannotJudge;

    /** A file of this name in the placement's own directory, removed if it was there; or why it cannot be. */
    public function fresh(string $name): string|CannotJudge;

    /** A file of this name in the placement's own directory, holding these contents; or why it cannot be. */
    public function written(string $name, string $contents): string|CannotJudge;
}
