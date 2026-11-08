<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Comment;

/**
 * What a passing run writes, as `comment.passing` says (ADR-0102): an update of a comment that is already there
 * and never a new one, a comment as any other run writes one, or nothing.
 */
enum Passing: string
{
    case Update = 'update';
    case Always = 'always';
    case Never = 'never';
}
