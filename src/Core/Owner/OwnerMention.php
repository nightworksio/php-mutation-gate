<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Owner;

/**
 * `owners.mention`: whether the pull request comment mentions an owner in the
 * update that first gives it a survivor, and names it without a mention after
 * that, or never mentions anyone (ADR-0022).
 */
enum OwnerMention: string
{
    case First = 'first';
    case Never = 'never';
}
