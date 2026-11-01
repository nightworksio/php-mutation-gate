<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Delivery;

use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Plan\PlannedWork;
use NightWorksIO\MutationGate\Core\Recheck\Rechecked;
use NightWorksIO\MutationGate\Core\Written;

/**
 * A sticky comment on a pull request or merge request: one comment, found by its marker and updated in place on
 * every run. The plan writes its planned state, a re-check writes over that state alone, and the verdict replaces
 * it (ADR-0009, ADR-0019, ADR-0024). Each state is written now or, under `--deliver-later`, left in a delivery
 * for `deliver`, which writes the comment with the credential its own environment holds.
 */
interface Commenting
{
    /** The comment in its planned state, written now. */
    public function planned(PlannedWork $work): Written|NotWritten;

    /** A delivery, with the comment in its planned state, where the run comments; unchanged on any other run. */
    public function plannedLater(PlannedWork $work, Delivery $delivery): Delivery;

    /** The comment while the last run's survivors are re-checked, written only over its planned state. */
    public function rechecked(Rechecked $rechecked): Written|NotWritten;

    /** A delivery, with the comment while the survivors are re-checked, where the run comments. */
    public function recheckedLater(Rechecked $rechecked, Delivery $delivery): Delivery;

    /** The comment, holding this markdown, written or updated in place: what `deliver` writes of a delivery's. */
    public function write(string $markdown): Written|NotWritten;

    /** The comment, holding this markdown, written only over its planned state. */
    public function writeOverPlanned(string $markdown): Written|NotWritten;
}
