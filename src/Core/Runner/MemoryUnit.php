<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

/** A unit PHP's `memory_limit` shorthand counts memory in: bytes, or K, M or G of them. */
enum MemoryUnit: string
{
    case Bytes = '';
    case Kilobytes = 'K';
    case Megabytes = 'M';
    case Gigabytes = 'G';

    /** How many bytes a kilobyte is, as PHP counts one. */
    private const int KILO = 1024;

    /** How many bytes one of the unit is. */
    public function bytes(): int
    {
        return match ($this) {
            self::Bytes => 1,
            self::Kilobytes => self::KILO,
            self::Megabytes => self::KILO * self::KILO,
            self::Gigabytes => self::KILO * self::KILO * self::KILO,
        };
    }
}
