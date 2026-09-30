<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\Mutant\IdPrefix;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use Traversable;

use function usort;

/**
 * Every record the ledgers read hold of the mutants an id or a prefix of one
 * names: each proof that holds one, with the mutant as it proved it, the
 * newest first. A mutant's history is its records (ADR-0014 decision 13).
 *
 * @implements IteratorAggregate<int, Recorded>
 */
final readonly class Records implements Countable, IteratorAggregate
{
    /** @param list<Recorded> $records the newest first */
    private function __construct(private IdPrefix $sought, private array $records)
    {
    }

    /** No record yet of the mutants the id or prefix names. */
    public static function none(IdPrefix $sought): self
    {
        return new self($sought, []);
    }

    /** These records, and those a scope's ledger holds. */
    public function in(Scope $scope, Ledger $ledger): self
    {
        $records = $this->records;

        foreach ($ledger->proofs() as $proof) {
            $records = [...$records, ...$this->naming($this->sought, $scope, $proof)];
        }

        usort($records, $this->newestFirst(...));

        return new self($this->sought, $records);
    }

    /** The newest record of the one mutant sought; none, or each mutant a prefix names where it names several. */
    public function newest(): Recorded|NoRecord|Ambiguous
    {
        $ids = $this->ids();

        return match (true) {
            $this->records === [] => NoRecord::of($this->sought),
            count($ids) > 1 => Ambiguous::of($this->sought, $ids),
            default => $this->records[0],
        };
    }

    /** Every mutant these records hold, the most recently recorded first. */
    public function ids(): MutantIds
    {
        $ids = MutantIds::none();

        foreach ($this->records as $record) {
            $ids = $ids->and(MutantIds::of($record->mutant()->id()));
        }

        return $ids;
    }

    public function count(): int
    {
        return count($this->records);
    }

    /** @return Traversable<int, Recorded> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->records);
    }

    /** @return list<Recorded> the mutants of a proof the id or prefix names */
    private function naming(IdPrefix $sought, Scope $scope, Proof $proof): array
    {
        $named = [];

        foreach ([...$proof->reported(), ...$proof->kills()] as $mutant) {
            $named = $sought->names($mutant->id()) ? [...$named, Recorded::in($scope, $proof, $mutant)] : $named;
        }

        return $named;
    }

    private function newestFirst(Recorded $one, Recorded $other): int
    {
        $first = $one->proof()->run()->at();
        $second = $other->proof()->run()->at();

        return match (true) {
            $first->isAfter($second) => -1,
            $second->isAfter($first) => 1,
            default => 0,
        };
    }
}
