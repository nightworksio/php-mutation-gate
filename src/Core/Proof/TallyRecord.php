<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\Cost\MutatorTallies;
use NightWorksIO\MutationGate\Core\Cost\MutatorTally;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;

use function sprintf;

/**
 * What a ledger tallied of each mutator's mutants, as its optional `tally`
 * section holds it (ADR-0076): by the runner's name, then the mutator's, how
 * many mutants it made, how many were killed, survived and ignored, and the
 * runner seconds they took.
 *
 * Reading keeps each well-formed tally and drops anything else, never
 * repairing it; a ledger without the section tallied nothing, which loses
 * the mutator report, never a verdict, so the ledger's format does not count
 * it.
 *
 * @internal the shape of the `tally` section of the ledger file
 *
 * @phpstan-type Written array<string, array<string, array{
 *     mutants: int,
 *     killed: int,
 *     survived: int,
 *     ignored: int,
 *     seconds: float,
 * }>>
 */
final readonly class TallyRecord
{
    public const string SECTION = 'tally';

    private const string MUTANTS = 'mutants';

    private const string KILLED = 'killed';

    private const string SURVIVED = 'survived';

    private const string IGNORED = 'ignored';

    /** @return Written */
    public static function of(MutatorTallies $tallies): array
    {
        $written = [];

        foreach ($tallies as $tally) {
            $written[$tally->runner()][$tally->mutator()] = [
                self::MUTANTS => $tally->mutants(),
                self::KILLED => $tally->killed(),
                self::SURVIVED => $tally->survived(),
                self::IGNORED => $tally->ignored(),
                LedgerFile::SECONDS => $tally->seconds()->seconds(),
            ];
        }

        return $written;
    }

    /** What a ledger's `tally` section holds; nothing where it holds none. */
    public static function read(Node $section): MutatorTallies
    {
        $tallies = MutatorTallies::none();

        foreach (self::entriesOf($section) as $runner => $mutators) {
            foreach (self::entriesOf($mutators) as $mutator => $entry) {
                foreach (self::tallyIn(sprintf('%s', $runner), sprintf('%s', $mutator), $entry) as $tally) {
                    $tallies = $tallies->with($tally);
                }
            }
        }

        return $tallies;
    }

    /** @return list<MutatorTally> the tally an entry holds, or none where it is malformed */
    private static function tallyIn(string $runner, string $mutator, Node $entry): array
    {
        try {
            $tally = MutatorTally::written(
                $runner,
                $mutator,
                $entry->field(self::MUTANTS)->integer(),
                $entry->field(self::KILLED)->integer(),
                $entry->field(self::SURVIVED)->integer(),
                $entry->field(self::IGNORED)->integer(),
                $entry->field(LedgerFile::SECONDS)->number(),
            );
        } catch (NotInShape) {
            return [];
        }

        return $tally instanceof MutatorTally ? [$tally] : [];
    }

    /** @return array<array-key, Node> */
    private static function entriesOf(Node $map): array
    {
        try {
            return $map->entries();
        } catch (NotInShape) {
            return [];
        }
    }
}
