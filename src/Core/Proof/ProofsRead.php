<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use ArrayObject;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
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
    private const string RAN = '%s %s %s';

    /**
     * @param ArrayObject<string, Path> $units each unit read so far, by its path
     * @param ArrayObject<string, Run>  $runs  each run read so far, by its id, its time and its base
     */
    private function __construct(
        private KilledRecords $killed,
        private InputsTable $inputs,
        private ArrayObject $units,
        private ArrayObject $runs,
    ) {
    }

    public static function of(KilledRecords $killed, InputsTable $inputs): self
    {
        return new self($killed, $inputs, new ArrayObject(), new ArrayObject());
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
    public function run(string $id, Instant $at, Digest $base): Run
    {
        return $this->runs[sprintf(self::RAN, $id, $at->value(), $base->value())] ??= Run::of($id, $at, $base);
    }
}
