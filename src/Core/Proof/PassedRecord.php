<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function array_map;

use NightWorksIO\MutationGate\Core\Change\Commit;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Time\Instant;

/**
 * A commit whose verdict passed as a file the gate writes holds it: `commit`,
 * its full id, `check`, the check-run the verdict reported under,
 * `ownScopeProofs`, how many proofs of the scope's own ledger it used,
 * `ownScopeCoverage`, true only where its coverage map was measured against
 * the map the scope keeps, `at`, the instant it passed, where it is known,
 * `gate`, the release of the gate that judged it, where it is known, and
 * `ownScopeUnits`, the paths of the units it took from its own scope's proofs,
 * where it took any (ADR-0139).
 *
 * @internal the shape of the ledger and plan files
 *
 * @phpstan-type Written array{
 *     commit: string,
 *     check: string,
 *     ownScopeProofs: int,
 *     ownScopeCoverage?: true,
 *     at?: string,
 *     gate?: string,
 *     ownScopeUnits?: list<string>,
 * }
 */
final readonly class PassedRecord
{
    /** The field that says its coverage map was measured against the map its own scope keeps. */
    public const string OWN_SCOPE_COVERAGE = 'ownScopeCoverage';

    private const string COMMIT = 'commit';

    private const string CHECK = 'check';

    private const string OWN_SCOPE_PROOFS = 'ownScopeProofs';

    private const string AT = ProofRecord::AT;

    private const string GATE = 'gate';

    private const string OWN_SCOPE_UNITS = 'ownScopeUnits';

    /** @return Written */
    public static function of(Passed $passed): array
    {
        $at = $passed->at();
        $units = $passed->ownScopeUnits();

        return [
            self::COMMIT => $passed->commit()->name(),
            self::CHECK => $passed->check(),
            self::OWN_SCOPE_PROOFS => $passed->ownScopeProofs(),
            ...$passed->measuredOnOwnScope() ? [self::OWN_SCOPE_COVERAGE => true] : [],
            ...$at instanceof Instant ? [self::AT => $at->value()] : [],
            ...$passed->gate()->isRecorded() ? [self::GATE => $passed->gate()->value()] : [],
            ...$units instanceof Paths && $units->count() > 0
                ? [self::OWN_SCOPE_UNITS => array_map(static fn(Path $path): string => $path->value(), [...$units])]
                : [],
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
        $units = $record->field(self::OWN_SCOPE_UNITS);
        $read = $units->isPresent() ? $read->takingOwnScope(self::units($units)) : $read;
        $gate = $record->field(self::GATE);
        $read = $gate->isPresent() ? $read->judgedBy(GateRelease::spelt($gate->text())) : $read;
        $at = $record->field(self::AT);

        if (! $at->isPresent()) {
            return $read;
        }

        $instant = Instant::parse($at->text());

        return $instant instanceof Instant ? $read->passedAt($instant) : throw NotInShape::at($at->at(), 'an instant');
    }

    /**
     * The paths a record lists.
     *
     * @throws NotInShape where it lists anything but paths
     */
    private static function units(Node $listed): Paths
    {
        $paths = [];

        foreach ($listed->items() as $item) {
            $paths[] = Path::of($item->text());
        }

        return Paths::of(...$paths);
    }
}
