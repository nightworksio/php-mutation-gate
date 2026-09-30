<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor;

use function array_values;

use ArrayIterator;
use IteratorAggregate;
use Traversable;

/**
 * Every ledger the proof store keeps, one per scope, as `doctor` weighs them.
 *
 * @implements IteratorAggregate<int, KeptLedger>
 */
final readonly class KeptLedgers implements IteratorAggregate
{
    /** @param list<KeptLedger> $ledgers */
    private function __construct(private array $ledgers)
    {
    }

    public static function of(KeptLedger ...$ledgers): self
    {
        return new self(array_values($ledgers));
    }

    /** @return Traversable<int, KeptLedger> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->ledgers);
    }
}
