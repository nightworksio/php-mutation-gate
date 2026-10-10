<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\Change\Commit;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Time\Instant;

/**
 * A commit whose verdict passed as a file the gate writes holds it: `commit`,
 * its full id, `check`, the check-run the verdict reported under,
 * `ownScopeProofs`, how many proofs of the scope's own ledger it used,
 * `ownScopeCoverage`, true only where its coverage map was measured against
 * the map the scope keeps, and `at`, the instant it passed, where it is known.
 *
 * @internal the shape of the ledger and plan files
 *
 * @phpstan-type Written array{commit: string, check: string, ownScopeProofs: int, ownScopeCoverage?: true, at?: string}
 */
final readonly class PassedRecord
{
    /** The field that says its coverage map was measured against the map its own scope keeps. */
    public const string OWN_SCOPE_COVERAGE = 'ownScopeCoverage';

    private const string COMMIT = 'commit';

    private const string CHECK = 'check';

    private const string OWN_SCOPE_PROOFS = 'ownScopeProofs';

    private const string AT = ProofRecord::AT;

    /** @return Written */
    public static function of(Passed $passed): array
    {
        $at = $passed->at();

        return [
            self::COMMIT => $passed->commit()->name(),
            self::CHECK => $passed->check(),
            self::OWN_SCOPE_PROOFS => $passed->ownScopeProofs(),
            ...$passed->measuredOnOwnScope() ? [self::OWN_SCOPE_COVERAGE => true] : [],
            ...$at instanceof Instant ? [self::AT => $at->value()] : [],
        ];
    }

    /**
     * The pass a record holds.
     *
     * @throws NotInShape where it is not so shaped
     */
    public static function read(Node $record): Passed
    {
        $own = $record->field(self::OWN_SCOPE_PROOFS)->integer();
        $commit = Commit::parse($record->field(self::COMMIT)->text());
        $read = Passed::of(
            $commit instanceof Commit
                ? $commit->revision()
                : throw NotInShape::at($record->field(self::COMMIT)->at(), 'a commit'),
            $record->field(self::CHECK)->text(),
            $own >= 0 ? $own : throw NotInShape::at($record->field(self::OWN_SCOPE_PROOFS)->at(), 'a count'),
        );
        $coverage = $record->field(self::OWN_SCOPE_COVERAGE);
        $read = match (true) {
            ! $coverage->isPresent() => $read,
            $coverage->boolean() => $read->onOwnScopeCoverage(),
            default => throw NotInShape::at($coverage->at(), 'true'),
        };
        $at = $record->field(self::AT);

        if (! $at->isPresent()) {
            return $read;
        }

        $instant = Instant::parse($at->text());

        return $instant instanceof Instant ? $read->passedAt($instant) : throw NotInShape::at($at->at(), 'an instant');
    }
}
