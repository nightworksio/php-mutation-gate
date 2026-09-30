<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use function chgrp;
use function chmod;
use function chown;
use function closedir;

use Closure;

use function explode;
use function fclose;
use function feof;
use function fflush;
use function file_exists;
use function flock;
use function fopen;
use function fread;
use function fseek;
use function fstat;
use function ftell;
use function ftruncate;
use function fwrite;
use function getenv;
use function is_array;
use function is_int;
use function is_link;
use function is_resource;
use function is_string;
use function lstat;
use function max;
use function mkdir;
use function opendir;
use function readdir;
use function realpath;
use function rename;
use function rewinddir;
use function rmdir;
use function sprintf;
use function stat;
use function str_contains;
use function stream_wrapper_register;
use function stream_wrapper_restore;
use function stream_wrapper_unregister;
use function touch;
use function unlink;
use function var_export;

/**
 * The gate's own `file://` wrapper, which the override registers before
 * Composer's autoloader loads anything (ADR-0023 decision 9). Where PHP
 * includes the one file it serves, it opens the mutated file in its place;
 * every other use of every file it hands to PHP's own wrapper.
 *
 * PHP loads it before Composer's autoloader, so it names no other class of
 * this package.
 *
 * Its methods are PHP's `streamWrapper` protocol, which PHP calls on this
 * class for every file operation while it stands in, so it declares all of
 * them. It holds the resources PHP hands it, which have no native type, and
 * `$context`, which PHP writes from global scope and PHP's own `file://`
 * wrapper has no use for.
 */
final class MutantFile
{
    /** The variable that names the file it serves and the mutated file: `<original>=<mutated>`. */
    public const string VARIABLE = 'MUTATION_GATE_MUTANT';

    /** The separator of the original path and the mutated one, in the variable that names them. */
    public const string PAIR = '=';
    /** The protocol it stands in for. */
    private const string PROTOCOL = 'file';

    /** The bit PHP sets in `stream_open`'s options when it opens a file for `include` or `require`. */
    private const int FOR_INCLUDE = 0x80;

    /** PHP's stream context for the call, which PHP sets on every wrapper it makes. */
    public mixed $context = null;

    /** The file it serves, as its real path, and the mutated file served in its place. */
    private static string $original = '';

    private static string $mutated = '';

    /** The file or directory PHP's own wrapper opened. */
    private mixed $handle = null;

    /** Serves the mutant its variable names, where it names one: the override's one call. */
    public static function serveFromEnvironment(): void
    {
        $pair = getenv(self::VARIABLE);

        if (! is_string($pair) || ! str_contains($pair, self::PAIR)) {
            return;
        }

        [$original, $mutated] = explode(self::PAIR, $pair, 2);
        self::$original = (string) realpath($original);
        self::$mutated = $mutated;
        stream_wrapper_unregister(self::PROTOCOL);
        stream_wrapper_register(self::PROTOCOL, self::class);
    }

    /** The line of the override's script that registers the wrapper, naming the file this class is in. */
    public static function registering(): string
    {
        return sprintf("require %s;\n\\%s::serveFromEnvironment();\n", var_export(__FILE__, return: true), self::class);
    }

    public function stream_open(string $path, string $mode, int $options): bool
    {
        $served = ($options & self::FOR_INCLUDE) !== 0 && $this->isServed($path) ? self::$mutated : $path;
        $includePath = ($options & STREAM_USE_PATH) !== 0;
        $this->handle = $this->natively(static fn(): mixed => fopen($served, $mode, use_include_path: $includePath));

        return is_resource($this->handle);
    }

    public function stream_read(int $count): string|false
    {
        return is_resource($this->handle) ? fread($this->handle, max(1, $count)) : false;
    }

    public function stream_write(string $data): int|false
    {
        return is_resource($this->handle) ? fwrite($this->handle, $data) : false;
    }

    public function stream_eof(): bool
    {
        return ! is_resource($this->handle) || feof($this->handle);
    }

    public function stream_close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    /** @return array<int|string, int>|false */
    public function stream_stat(): array|false
    {
        return is_resource($this->handle) ? fstat($this->handle) : false;
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        return is_resource($this->handle) && fseek($this->handle, $offset, $whence) === 0;
    }

    public function stream_tell(): int|false
    {
        return is_resource($this->handle) ? ftell($this->handle) : false;
    }

    public function stream_flush(): bool
    {
        return is_resource($this->handle) && fflush($this->handle);
    }

    /** Locks the open file, or, asked with no operation, says that it can, as PHP asks before `LOCK_EX` writes. */
    public function stream_lock(int $operation): bool
    {
        $locking = $operation & (LOCK_SH | LOCK_EX | LOCK_UN | LOCK_NB);

        return is_resource($this->handle) && ($locking === 0 || flock($this->handle, $locking));
    }

    public function stream_truncate(int $size): bool
    {
        return is_resource($this->handle) && $size >= 0 && ftruncate($this->handle, $size);
    }

    /** Blocking, timeouts and buffers mean nothing to a file on disk, so it takes none of them. */
    public function stream_set_option(): bool
    {
        return false;
    }

    /** The open file itself, for `stream_select()`. */
    public function stream_cast(): mixed
    {
        return is_resource($this->handle) ? $this->handle : false;
    }

    /** @param array{int, int}|int|string $value the times to touch, the mode, or the owner or group */
    public function stream_metadata(string $path, int $option, array|int|string $value): bool
    {
        return $this->natively(static fn(): bool => match (true) {
            $option === STREAM_META_TOUCH && is_array($value) => touch($path, $value[0], $value[1]),
            $option === STREAM_META_TOUCH => touch($path),
            $option === STREAM_META_ACCESS && is_int($value) => chmod($path, $value),
            ($option === STREAM_META_OWNER_NAME || $option === STREAM_META_OWNER) && ! is_array($value)
                => chown($path, $value),
            ($option === STREAM_META_GROUP_NAME || $option === STREAM_META_GROUP) && ! is_array($value)
                => chgrp($path, $value),
            default => false,
        });
    }

    /** @return array<int|string, int>|false */
    public function url_stat(string $path, int $flags): array|false
    {
        $stat = $this->natively(static fn(): array|false => match (true) {
            ($flags & STREAM_URL_STAT_QUIET) !== 0 && ! file_exists($path) && ! is_link($path) => false,
            ($flags & STREAM_URL_STAT_LINK) !== 0 => lstat($path),
            default => stat($path),
        });

        return is_array($stat) ? $stat : false;
    }

    public function unlink(string $path): bool
    {
        return $this->natively(static fn(): bool => unlink($path));
    }

    public function rename(string $from, string $to): bool
    {
        return $this->natively(static fn(): bool => rename($from, $to));
    }

    public function mkdir(string $path, int $mode, int $options): bool
    {
        $recursive = ($options & STREAM_MKDIR_RECURSIVE) !== 0;

        return $this->natively(static fn(): bool => mkdir($path, $mode, $recursive));
    }

    public function rmdir(string $path): bool
    {
        return $this->natively(static fn(): bool => rmdir($path));
    }

    public function dir_opendir(string $path): bool
    {
        $this->handle = $this->natively(static fn(): mixed => opendir($path));

        return is_resource($this->handle);
    }

    public function dir_readdir(): string|false
    {
        return is_resource($this->handle) ? readdir($this->handle) : false;
    }

    public function dir_rewinddir(): bool
    {
        if (is_resource($this->handle)) {
            rewinddir($this->handle);
        }

        return is_resource($this->handle);
    }

    public function dir_closedir(): bool
    {
        if (is_resource($this->handle)) {
            closedir($this->handle);
        }

        return true;
    }

    /** Whether a path PHP opens is the file served, as written or by its real path. */
    private function isServed(string $path): bool
    {
        return self::$original !== '' && ($path === self::$original || realpath($path) === self::$original);
    }

    /**
     * Runs an operation on PHP's own wrapper, and stands in for it again after.
     *
     * @template T
     *
     * @param  Closure(): T $operation
     * @return T
     */
    private function natively(Closure $operation): mixed
    {
        stream_wrapper_restore(self::PROTOCOL);

        try {
            return $operation();
        } finally {
            stream_wrapper_unregister(self::PROTOCOL);
            stream_wrapper_register(self::PROTOCOL, self::class);
        }
    }
}
