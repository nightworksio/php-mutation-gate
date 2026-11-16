<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function implode;

use NightWorksIO\MutationGate\Core\Format\Bytes;
use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;

/**
 * What a machine has left, in bytes, each part where its system says it: the
 * memory a process can still take of all there is, the swap used of all
 * there is, and the disk free.
 *
 * @internal
 */
final readonly class Remaining
{
    private const string MEMORY = 'memory %.1f of %.1f GB free';

    private const string SWAP = 'swap %.1f of %.1f GB used';

    private const string DISK = 'disk %.1f GB free';

    /**
     * @param array{int, int}|NotGiven $memory free and all there is
     * @param array{int, int}|NotGiven $swap   used and all there is
     */
    private function __construct(
        private array|NotGiven $memory,
        private array|NotGiven $swap,
        private int|NotGiven $disk,
    ) {
    }

    /** Nothing a system said. */
    public static function unknown(): self
    {
        return new self(NotGiven::value(), NotGiven::value(), NotGiven::value());
    }

    /** This, with the memory a process can still take of all there is. */
    public function withMemory(int $free, int $total): self
    {
        return new self([$free, $total], $this->swap, $this->disk);
    }

    /** This, with the swap used of all there is. */
    public function withSwap(int $used, int $total): self
    {
        return new self($this->memory, [$used, $total], $this->disk);
    }

    /** This, with the disk free. */
    public function withDisk(int $free): self
    {
        return new self($this->memory, $this->swap, $free);
    }

    /** Each part the system said, in gigabytes; nothing where it said none. */
    public function text(): string
    {
        $parts = [
            ...$this->memory instanceof NotGiven ? [] : [sprintf(self::MEMORY, ...$this->gigabytes($this->memory))],
            ...$this->swap instanceof NotGiven ? [] : [sprintf(self::SWAP, ...$this->gigabytes($this->swap))],
            ...$this->disk instanceof NotGiven ? [] : [sprintf(self::DISK, $this->disk / Bytes::PER_GIGABYTE)],
        ];

        return implode(', ', $parts);
    }

    /**
     * @param  array{int, int}     $bytes
     * @return array{float, float}
     */
    private function gigabytes(array $bytes): array
    {
        return [$bytes[0] / Bytes::PER_GIGABYTE, $bytes[1] / Bytes::PER_GIGABYTE];
    }
}
