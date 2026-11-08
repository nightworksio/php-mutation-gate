<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Comment;

/** Which survivors the comment lists, as `comment.survivors.lines` says (ADR-0102): those on changed lines, or all. */
enum Lines: string
{
    case Changed = 'changed';
    case All = 'all';
}
