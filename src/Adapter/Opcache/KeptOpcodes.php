<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Opcache;

use function array_intersect_key;
use function array_key_exists;
use function array_slice;
use function count;

/**
 * The opcodes of the programs this process has compiled, each by everything
 * its compile reads: the PHP, the child's settings and the program's text
 * (ADR-0260). Opcache compiles a file without reading any other, so a
 * program compiled again compiles to the same opcodes. Only programs that
 * compiled are kept, never one that failed or ran out of time. The newest
 * are kept, as many as it may keep, and none where that is none.
 *
 * @internal
 */
final class KeptOpcodes
{
    private static ?self $process = null;

    /** @var array<string, Opcodes> each program's opcodes by its key, the one used last at the end */
    private array $kept = [];

    public function __construct(private int $most)
    {
    }

    /** The programs this process keeps, at most this many from now on. */
    public static function inProcess(int $most): self
    {
        self::$process ??= new self($most);
        self::$process->most = $most;
        self::$process->trimmed();

        return self::$process;
    }

    /**
     * The opcodes kept of the programs with these keys, by each program's key in the call; none of a program not
     * kept.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, string>  $keys
     * @return array<TKey, Opcodes>
     */
    public function of(array $keys): array
    {
        $found = [];

        foreach ($keys as $at => $key) {
            if (array_key_exists($key, $this->kept)) {
                $opcodes = $this->kept[$key];
                unset($this->kept[$key]);
                $this->kept[$key] = $opcodes;
                $found[$at] = $opcodes;
            }
        }

        return $found;
    }

    /**
     * Each of these programs that compiled, by its key.
     *
     * @template TKey of array-key
     *
     * @param array<TKey, string>             $keys
     * @param array<TKey, Opcodes|Uncompiled> $compiled
     */
    public function keep(array $keys, array $compiled): void
    {
        foreach (array_intersect_key($keys, $compiled) as $at => $key) {
            if ($compiled[$at] instanceof Opcodes) {
                unset($this->kept[$key]);
                $this->kept[$key] = $compiled[$at];
            }
        }

        $this->trimmed();
    }

    private function trimmed(): void
    {
        $over = count($this->kept) - $this->most;

        if ($over > 0) {
            $this->kept = array_slice($this->kept, $over, preserve_keys: true);
        }
    }
}
