<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

/** Why a store could not read a scope's ledger, where one may be there. */
enum UnreadReason: string
{
    /** The store answered with a refusal, such as a server's error or a denied read. */
    case Refused = 'refused';

    /** The ledger is larger than a store reads. */
    case TooLarge = 'too-large';

    /** The store did not answer in time. */
    case TimedOut = 'timed-out';

    /** The store could not be reached. */
    case Unreachable = 'unreachable';

    /** What the store holds is no ledger this gate reads. */
    case Malformed = 'malformed';
}
