<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Runtime;

use function array_key_exists;
use function disk_free_space;
use function file_get_contents;
use function is_dir;
use function is_float;
use function is_readable;
use function is_string;

use NightWorksIO\MutationGate\Core\Runner\Gauge;
use NightWorksIO\MutationGate\Core\Runner\Remaining;

use function preg_match_all;

use const PREG_SET_ORDER;

/**
 * What this machine has left: memory and swap as Linux's `/proc/meminfo`
 * gives them, `MemAvailable` of `MemTotal` and `SwapTotal` less `SwapFree`,
 * in kibibytes, where the file can be read, and the disk free under the
 * directory the gate writes to.
 *
 * @internal
 */
final readonly class SystemGauge implements Gauge
{
    /** Where Linux says what memory and swap it has. */
    public const string MEMINFO = '/proc/meminfo';

    private const string AVAILABLE = 'MemAvailable';

    private const string MEMORY = 'MemTotal';

    private const string SWAP_FREE = 'SwapFree';

    private const string SWAP = 'SwapTotal';

    /** Bytes in the kibibyte `/proc/meminfo` counts in. */
    private const int PER_KIBIBYTE = 1024;

    /** A line of `/proc/meminfo`: a field's name, and its number of kibibytes. */
    private const string FIELD = '/^(?<name>\w+):\s+(?<kibibytes>\d+) kB$/m';

    public function __construct(private string $meminfo, private string $directory)
    {
    }

    public function remaining(): Remaining
    {
        $free = is_dir($this->directory) ? disk_free_space($this->directory) : false;
        $said = is_readable($this->meminfo) ? file_get_contents($this->meminfo) : false;
        $remaining = $this->said(is_string($said) ? $said : '');

        return is_float($free) ? $remaining->withDisk((int) $free) : $remaining;
    }

    /** The memory and swap a text of `/proc/meminfo` says, each where it says both its fields. */
    private function said(string $meminfo): Remaining
    {
        preg_match_all(self::FIELD, $meminfo, $found, PREG_SET_ORDER);
        $bytes = [];

        foreach ($found as $field) {
            $bytes[$field['name']] = (int) $field['kibibytes'] * self::PER_KIBIBYTE;
        }

        $memory = $this->both($bytes, self::AVAILABLE, self::MEMORY);
        $swap = $this->both($bytes, self::SWAP_FREE, self::SWAP);
        $remaining = $memory === [] ? Remaining::unknown() : Remaining::unknown()->withMemory(...$memory);

        return $swap === [] ? $remaining : $remaining->withSwap($swap[1] - $swap[0], $swap[1]);
    }

    /**
     * Two fields' bytes, where both are given.
     *
     * @param  array<string, int>  $bytes by field
     * @return array{}|array{int, int}
     */
    private function both(array $bytes, string $one, string $other): array
    {
        return array_key_exists($one, $bytes) && array_key_exists($other, $bytes) ? [$bytes[$one], $bytes[$other]] : [];
    }
}
