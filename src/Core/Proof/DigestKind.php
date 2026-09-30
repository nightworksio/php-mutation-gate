<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

/**
 * What a proof records a digest of, beside its key (ADR-0008, decision 1): the
 * inputs that decide whether a result of other code can still stand for the
 * code on disk.
 */
enum DigestKind: string
{
    /** The unit's source: its file, or every file inside its held path. */
    case Source = 'source';

    /** What decides its mutant set besides the source: the gate, the config, the runner and its setup. */
    case Mutation = 'mutation';

    /** A test file that killed one of its mutants, with the support it reads. */
    case Test = 'tests';
}
