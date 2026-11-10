<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Port;

use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Reach\GraphAnswer;
use NightWorksIO\MutationGate\Core\Reach\GraphQuestion;

/**
 * Outbreak's static graph of a project's sources and tests (ADR-0132),
 * reached by its binary. The coverage map stays the check: a run adds or
 * orders by what it answers, and where it answers nothing the map's answer
 * stands alone.
 */
interface ReachGraph
{
    /** What the graph answers to a question; or why it answers nothing. */
    public function answered(GraphQuestion $question): GraphAnswer|NotWritten;
}
