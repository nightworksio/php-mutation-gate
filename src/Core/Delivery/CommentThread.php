<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Delivery;

use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Written;

/**
 * Where a forge keeps a pull request's or merge request's comments: the one place a sticky comment's markdown is
 * turned into what the forge shows and written through its API (ADR-0024).
 *
 * @internal
 */
interface CommentThread
{
    /**
     * The comment, holding this markdown, written as this says: the one found by its marker updated in place, or
     * posted where there is none; where asked, only over its planned state, and otherwise left as it is with why
     * (CommentStates::NOT_PLANNED); where asked, only where the gate has commented before (CommentStates::NOT_THERE);
     * or why it is not written.
     */
    public function written(string $markdown, CommentWrite $write): Written|NotWritten;
}
