<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Comment;

/**
 * A kind of run that writes a comment, as `comment.on` names it (ADR-0102): a pull or merge request's, or the
 * default branch's.
 */
enum Occasion: string
{
    case PullRequest = 'pull-request';
    case DefaultBranch = 'default-branch';
}
