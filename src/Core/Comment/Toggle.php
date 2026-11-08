<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Comment;

/**
 * Something the comment says or a run it is written on, which `comment.<toggle>` turns off (ADR-0102): what to try
 * with each survivor, how to reproduce it, and the comment of a draft, a sampled and a cut-short run.
 */
enum Toggle: string
{
    case Try = 'try';
    case Reproduce = 'reproduce';
    case Drafts = 'drafts';
    case Sampled = 'sampled';
    case CutShort = 'cutShort';
}
