<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator;

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use PhpParser\Node;

/**
 * A change the gate makes to code, written once against php-parser 5 and run
 * under every runner through the bridge the gate writes for it (ADR-0021). It
 * makes at most one change per node, and is constructed with no arguments.
 */
interface Mutator
{
    /** Its name in reports and ignores, `<set>/<Name>`, such as `laravel/GateAllowsToTrue`. */
    public function name(): MutatorName;

    /** The family whose hint a survivor gets (ADR-0009 decision 7). */
    public function family(): MutatorFamily;

    /** What it is about, such as `security`. */
    public function tags(): Tags;

    /** The node classes it looks at; no other node is ever offered to it. */
    public function handles(): NodeClasses;

    /** The one change for this node, or none. It never changes the node it is given. */
    public function mutate(Node $node): Node|Removal|Unchanged;

    /** A sentence for its survivors, or its family's. */
    public function hint(): Hint|FamilyHint;
}
