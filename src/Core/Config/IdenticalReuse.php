<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/** `reuse.identical`: where a passed verdict whose every input is identical stands for a run (ADR-0198). */
enum IdenticalReuse: string
{
    /** On pull requests and on the default branch. */
    case On = 'on';

    /** On pull requests alone: the default branch always judges. */
    case Pulls = 'pulls';

    /** Nowhere: every run judges. */
    case Off = 'off';

    /** The builder's call that sets it. */
    public function php(): string
    {
        return match ($this) {
            self::On => 'Reuse::identical()',
            self::Pulls => 'Reuse::identicalOnPullRequests()',
            self::Off => 'Reuse::neverIdentical()',
        };
    }

    /** Whether a run on this kind of scope reuses: the default branch's, or any other's. */
    public function reusesOn(bool $defaultBranch): bool
    {
        return $defaultBranch ? $this === self::On : $this !== self::Off;
    }
}
