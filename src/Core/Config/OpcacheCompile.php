<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Format\Bytes;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * `staticCheck.opcache`: how opcache compiles the programs that prove a
 * survivor equivalent (ADR-0013): how many programs one child compiles, how
 * long one child may take, and how many compiled programs one process keeps
 * so it compiles none of them again (ADR-0260).
 */
final readonly class OpcacheCompile
{
    /** How many programs one child compiles, where the config does not say. */
    public const int BATCH = 100;

    /** How long one child may take to compile its programs, in seconds, where the config does not say. */
    public const float LIMIT = 60.0;

    /**
     * An opcode dump's size, in bytes, at the 90th percentile of this
     * package's own source files (15,193 measured), rounded up to 16 KiB.
     */
    public const int DUMP = 16 * Bytes::PER_KIBIBYTE;

    /** The memory, in bytes, the dumps one process keeps take at most at that size. */
    public const int KEPT_MEMORY = 6 * Bytes::PER_MEBIBYTE;

    /** How many compiled programs one process keeps, where the config does not say: as many as fit in that memory. */
    public const int KEPT = self::KEPT_MEMORY / self::DUMP;

    private function __construct(private int $batch, private Seconds $limit, private int $kept)
    {
    }

    /** As many programs a child, as long a child, and as many kept, as these say. */
    public static function of(int $batch, Seconds $limit, int $kept): self
    {
        return new self($batch, $limit, $kept);
    }

    /** Each where the config does not say. */
    public static function standard(): self
    {
        return new self(self::BATCH, Seconds::of(self::LIMIT), self::KEPT);
    }

    /** `staticCheck.opcache.batch`: the programs one child compiles, so opcache's memory never fills. */
    public function batch(): int
    {
        return $this->batch;
    }

    /** `staticCheck.opcache.limit`: the longest one child may compile for. */
    public function limit(): Seconds
    {
        return $this->limit;
    }

    /** `staticCheck.opcache.kept`: how many compiled programs one process keeps, none where it is 0. */
    public function kept(): int
    {
        return $this->kept;
    }
}
