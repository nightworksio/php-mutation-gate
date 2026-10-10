<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Turbo;

/** What a request asks the helper to work out, as the protocol names it. */
enum Kind: string
{
    /** The key of each test file's coverage entries (ADR-0023, decision 1). */
    case EntryKeys = 'entry-keys';
}
