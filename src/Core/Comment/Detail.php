<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Comment;

/**
 * How the comment shows a survivor, as `comment.survivors.detail` says (ADR-0102): folded with its diff, hint,
 * what to try and how to reproduce it, or as one row of a table.
 */
enum Detail: string
{
    case Full = 'full';
    case Row = 'row';
}
