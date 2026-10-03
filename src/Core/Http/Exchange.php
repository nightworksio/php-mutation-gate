<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Http;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\LedgerLimits;
use NightWorksIO\MutationGate\Core\Proof\Unreadable;
use NightWorksIO\MutationGate\Core\Written;

/**
 * Where a store's requests go: the one place a proof store's HTTP happens,
 * following no redirect. A store builds its requests and reads their
 * answers; an adapter sends them.
 */
interface Exchange
{
    /** The answer to a request whose answer is small, such as a token's; or why none came. */
    public function answer(Request $request): Reply|CannotJudge;

    /**
     * The ledger's bytes a GET answers, read within the limits; an empty
     * ledger where there is none yet (404); or why they could not be read,
     * where the reader knows the ledger as `$from`.
     */
    public function fetch(Request $request, LedgerLimits $limits, string $from): string|Ledger|Unreadable;

    /** Send a ledger, saying it was written where the reader knows it as `$to`, or why not. */
    public function put(Request $request, LedgerLimits $limits, string $to): Written|NotWritten;
}
