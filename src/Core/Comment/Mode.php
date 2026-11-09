<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Comment;

/**
 * How a run's comment meets the comments before it, as `comment.mode` says (ADR-0102): one comment updated in
 * place, a new one each run, or a new one each run that hides the one before, where the channel can hide one.
 */
enum Mode: string
{
    case Sticky = 'sticky';
    case New = 'new';
    case NewHidingOld = 'new-hiding-old';
}
