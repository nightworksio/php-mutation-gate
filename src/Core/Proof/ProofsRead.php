<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function array_key_exists;

use ArrayObject;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Mutant\KilledRecords;
use NightWorksIO\MutationGate\Core\Time\Instant;

use function sprintf;

/**
 * What a ledger's proofs point into and share, as one read of the ledger
 * meets them: what its killed records point into, the digests of its inputs,
 * each unit once, and each run once. Many proofs are of one unit, and every
 * proof of one run shares it. What it has built is kept as it reads, and
 * never changes what it answers.
 *
 * @internal the shape of the ledger file
 */
final readonly class ProofsRead
{
    private const string RAN = '%s %s %s %s';

    /**
     * @param list<string>              $bases the ledger's bases, each at its index
     * @param ArrayObject<string, Path> $units each unit read so far, by its path
     * @param ArrayObject<string, Run>  $runs  each run read so far, by its id, its time, its base and its matrix
     */
    private function __construct(
        private KilledRecords $killed,
        private InputsTable $inputs,
        private array $bases,
        private ArrayObject $units,
        private ArrayObject $runs,
    ) {
    }

    /** @param list<string> $bases the ledger's bases, each at its index, as its file writes them */
    public static function of(KilledRecords $killed, InputsTable $inputs, array $bases = []): self
    {
        return new self($killed, $inputs, $bases, new ArrayObject(), new ArrayObject());
    }

    /**
     * The base an index into the ledger's bases names.
     *
     * @throws NotInShape
     */
    public function base(Node $index): Digest
    {
        $at = $index->integer();
        return array_key_exists($at, $this->bases) && Digest::isSha256($this->bases[$at])
            ? Digest::of($this->bases[$at])
            : throw NotInShape::at($index->at(), 'a base');
    }

    public function killed(): KilledRecords
    {
        return $this->killed;
    }

    public function inputs(): InputsTable
    {
        return $this->inputs;
    }

    /** A unit, one for every proof of it. */
    public function unit(string $path): Path
    {
        return $this->units[$path] ??= Path::of($path);
    }

    /** A run, one for every proof it established. */
    public function run(string $id, Instant $at, Digest $base, RunProfile $profile): Run
    {
        $kind = JsonText::compact(RunProfileRecord::of($profile));

        return $this->runs[sprintf(self::RAN, $id, $at->value(), $base->value(), $kind)]
            ??= Run::of($id, $at, $base)->profiled($profile);
    }
}
