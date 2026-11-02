<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Delivery;

use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Written;

/**
 * Where a forge keeps a pull request's or merge request's comments: the one place a sticky comment's markdown is
 * turned into what the forge shows and written through its API (ADR-0024).
 */
interface CommentThread
{
    /**
     * The sticky comment, holding this markdown, written or updated in place; where asked, only over its planned
     * state, and otherwise left as it is with why (CommentStates::NOT_PLANNED); or why it is not written.
     */
    public function written(string $markdown, bool $overPlanned): Written|NotWritten;
}
