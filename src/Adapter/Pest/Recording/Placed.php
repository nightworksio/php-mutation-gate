<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * Where a killer stood in the order a mutant's own process started its
 * tests, as a killer line carries it: its position, from one, and the
 * digest of that order up to it (see OrderDigest), where it is the test
 * that process started last; and, always, the process, so the lines of two
 * runs on one mutated copy are told apart.
 */
final readonly class Placed
{
    private function __construct(private int $run, private int|NotGiven $at, private string|NotGiven $order)
    {
    }

    /** A killer at this position, with the digest of the order up to it, in the process with this id. */
    public static function at(int $position, string $order, int $run): self
    {
        return new self($run, $position, $order);
    }

    /** A killer the process with this id never started as a test of its own, as a class whose set-up failed. */
    public static function unplaced(int $run): self
    {
        return new self($run, NotGiven::value(), NotGiven::value());
    }

    /**
     * The fields a killer line carries of it.
     *
     * @return array<string, int|string>
     */
    public function fields(): array
    {
        return [
            ...($this->at instanceof NotGiven ? [] : [RecordField::At->value => $this->at]),
            ...($this->order instanceof NotGiven ? [] : [RecordField::Order->value => $this->order]),
            RecordField::Run->value => $this->run,
        ];
    }
}
