<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Subsumption\Subsumption;

use function sprintf;

/**
 * Which mutant of each file subsumes which, as a ledger's optional
 * `subsumption` section holds it (ADR-0079): by file, the id of each subsumed
 * mutant's subsumer by the subsumed mutant's id.
 *
 * Reading keeps each well-formed entry and drops anything else, never
 * repairing it; a ledger without the section learned nothing, which leaves
 * only the rule for negations to a local run, never a verdict, so the
 * ledger's format does not count it.
 *
 * @internal the shape of the `subsumption` section of the ledger file
 *
 * @phpstan-type Written array<string, array<string, string>>
 */
final readonly class SubsumptionRecord
{
    public const string SECTION = 'subsumption';

    /** @return Written */
    public static function of(Subsumption $subsumption): array
    {
        $written = [];

        foreach ($subsumption as $file => $subsumed) {
            foreach ($subsumed as [$mutant, $by]) {
                $written[$file][$mutant->value()] = $by->value();
            }
        }

        return $written;
    }

    /** What a ledger's `subsumption` section holds; nothing where it holds none. */
    public static function read(Node $section): Subsumption
    {
        $subsumption = Subsumption::none();

        foreach (self::entriesOf($section) as $file => $subsumed) {
            foreach (self::entriesOf($subsumed) as $mutant => $by) {
                $pair = self::pairOf(sprintf('%s', $mutant), $by);
                $path = Path::of(sprintf('%s', $file));
                $subsumption = $pair === [] ? $subsumption : $subsumption->with($path, ...$pair);
            }
        }

        return $subsumption;
    }

    /** @return list<MutantId> the subsumed mutant and its subsumer, or none where either id is malformed */
    private static function pairOf(string $mutant, Node $by): array
    {
        try {
            $subsumer = MutantId::parse($by->text());
        } catch (NotInShape) {
            return [];
        }

        $subsumed = MutantId::parse($mutant);

        return $subsumed instanceof MutantId && $subsumer instanceof MutantId ? [$subsumed, $subsumer] : [];
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
