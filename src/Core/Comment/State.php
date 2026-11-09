<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Comment;

/**
 * A state the comment is written in, as `comment.states` names it (ADR-0102): the plan's, the recheck's and the
 * verdict's.
 */
enum State: string
{
    case Planned = 'planned';
    case Rechecked = 'rechecked';
    case Verdict = 'verdict';
}
