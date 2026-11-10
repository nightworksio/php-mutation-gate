<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Serve;

use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Written;

/**
 * The proofs `outbreak serve` holds in memory, by key (ADR-0133), asked
 * beside the ledger they come from. An answer that does not come in time,
 * or any other, is no answer, and the gate reads the ledger itself. The
 * daemon keeps the static graph too, which the gate asks through the
 * `ReachGraph` port (ADR-0132).
 */
interface ServesState
{
    /**
     * The proof it holds under each of these keys, by the key; or why it answers none.
     *
     * @return array<string, string>|NotWritten
     */
    public function proofs(string ...$keys): array|NotWritten;

    /**
     * Each proof held and journalled under its key; or why none was.
     *
     * @param array<string, string> $proofs each proof's text, by its key
     */
    public function kept(array $proofs): Written|NotWritten;
}
