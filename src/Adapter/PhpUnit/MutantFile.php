<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use function chgrp;
use function chmod;
use function chown;
use function closedir;

use Closure;

use function fclose;
use function fflush;
use function file_exists;
use function file_put_contents;
use function flock;
use function fopen;
use function fread;
use function fseek;
use function fstat;
use function ftell;
use function ftruncate;
use function fwrite;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_int;
use function is_link;
use function is_resource;
use function lstat;
use function max;
use function mkdir;
use function opendir;
use function readdir;
use function rename;
use function rewinddir;
use function rmdir;
use function sprintf;
use function stat;
use function stream_resolve_include_path;
use function stream_set_blocking;
use function stream_wrapper_register;
use function stream_wrapper_restore;
use function stream_wrapper_unregister;
use function strpbrk;
use function touch;
use function unlink;
use function var_export;

/**
 * The gate's own `file://` wrapper, which the override registers before
 * Composer's autoloader loads anything (ADR-0023 decision 9). Where PHP
 * includes the one file it serves, by any name of that file, it opens the
 * mutated file in its place, and says so in the guard file; every other use
 * of every file it hands to PHP's own wrapper.
 *
 * PHP loads it before Composer's autoloader, so it names no other class of
 * this package.
 *
 * Its methods are PHP's `streamWrapper` protocol, which PHP calls on this
 * class for every file operation while it stands in, so it declares all of
 * them. It holds the resources PHP hands it, which have no native type, and
 * `$context`, which PHP writes from global scope and PHP's own `file://`
 * wrapper has no use for.
 *
 * What still differs from PHP's own wrapper: `stream_get_meta_data()` says
 * `user-space`; a failed open's warning names this class's method, and a
 * file that cannot be made to write it warns twice; and `feof()` after an
 * `fread()` asked for more than was left says the file is not yet at its
 * end, where PHP's own wrapper says it is: PHP asks a wrapper for one chunk
 * whatever a caller asks for, so the wrapper cannot tell that read from the
 * one a line reader makes, and it answers as a line reader needs.
 */
final class MutantFile
{
    /** What it writes to the guard file once it has served the mutated file. */
    public const string SERVED = 'served';

    /** The protocol it stands in for. */
    private const string PROTOCOL = 'file';

    /** The bit PHP sets in `stream_open`'s options when it opens a file for `include` or `require`. */
    private const int FOR_INCLUDE = 0x80;

    /** The characters of a mode that opens a file to write it, or makes it. */
    private const string WRITING = 'waxc+';

    /** PHP's stream context for the call, which PHP sets on every wrapper it makes. */
    public mixed $context = null;

    /** @var list<int> the device and the inode of the file it serves, which name it whatever path names it */
    private static array $served = [];

    /** The mutated file served in its place, and the guard file it says so in. */
    private static string $mutated = '';

    private static string $guard = '';

    /** The file or directory PHP's own wrapper opened. */
    private mixed $handle = null;

    /** Whether the last read of the open file got nothing, which is when PHP's own wrapper is at its end. */
    private bool $drained = false;

    /**
     * Serves a mutated file in place of a file, where both are named and the
     * file is there, saying so in the guard file: the override's one call.
     */
    public static function serve(string $original, string $mutated, string $guard): void
    {
        $stat = is_file($original) ? stat($original) : false;

        if (! is_array($stat) || in_array('', [$mutated, $guard], strict: true)) {
            return;
        }

        self::$served = [$stat['dev'], $stat['ino']];
        self::$mutated = $mutated;
        self::$guard = $guard;
        stream_wrapper_unregister(self::PROTOCOL);
        stream_wrapper_register(self::PROTOCOL, self::class);
    }

    /**
     * The lines of the override's script that register the wrapper, naming
     * the file this class is in and the variables that name the file served,
     * the mutated file and the guard file.
     */
    public static function registering(string $original, string $mutated, string $guard): string
    {
        return sprintf(
            "require %s;\n\\%s::serve((string) \\getenv(%s), (string) \\getenv(%s), (string) \\getenv(%s));\n",
            var_export(__FILE__, return: true),
            self::class,
            var_export($original, return: true),
            var_export($mutated, return: true),
            var_export($guard, return: true),
        );
    }

    /**
     * Opens a file, or the mutated file where PHP includes the file served.
     * A file that is not there is not opened to read it, so that the only
     * warning is PHP's own.
     */
    public function stream_open(string $path, string $mode, int $options): bool
    {
        $serving = ($options & self::FOR_INCLUDE) !== 0 && $this->isServed($path);
        $opened = $serving ? self::$mutated : $path;
        $usePath = ($options & STREAM_USE_PATH) !== 0;
        $there = strpbrk($mode, self::WRITING) !== false
            || $this->natively(static fn(): bool => stream_resolve_include_path($opened) !== false);
        $handle = $there ? $this->natively(static fn(): mixed => fopen($opened, $mode, $usePath)) : false;

        return is_resource($handle) && $this->opened($handle, $serving);
    }

    public function stream_read(int $count): string|false
    {
        $read = is_resource($this->handle) ? fread($this->handle, max(1, $count)) : false;
        $this->drained = in_array($read, ['', false], strict: true);

        return $read;
    }

    public function stream_write(string $data): int|false
    {
        return is_resource($this->handle) ? fwrite($this->handle, $data) : false;
    }

    /** Whether the open file is at its end: once a read got nothing, as PHP's own wrapper has it, until a seek. */
    public function stream_eof(): bool
    {
        return $this->drained;
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
        $this->drained = false;

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
        return is_resource($this->handle) && ftruncate($this->handle, max(0, $size));
    }

    /**
     * Sets whether the open file blocks, which is all PHP's own wrapper sets
     * of a file on disk: it has no read timeout and keeps no write buffer.
     */
    public function stream_set_option(int $option, int $value): bool
    {
        return $option === STREAM_OPTION_BLOCKING && is_resource($this->handle)
            && stream_set_blocking($this->handle, $value !== 0);
    }

    /** The open file itself, for `stream_select()`. */
    public function stream_cast(): mixed
    {
        return is_resource($this->handle) ? $this->handle : false;
    }

    /** @param array{}|array{int, int}|int|string $value no time, the times to touch, the mode, or an owner or group */
    public function stream_metadata(string $path, int $option, array|int|string $value): bool
    {
        return $this->natively(static fn(): bool => match (true) {
            is_array($value) => $value === [] ? touch($path) : touch($path, $value[0], $value[1]),
            is_int($value) && $option === STREAM_META_ACCESS => chmod($path, $value),
            $option === STREAM_META_OWNER_NAME || $option === STREAM_META_OWNER => chown($path, $value),
            default => chgrp($path, $value),
        });
    }

    /**
     * A path's status, or false, without a warning, where there is none to
     * give: PHP warns for itself where the caller asked it to. A link is
     * stated itself where PHP asks for the link, and otherwise what it
     * points at, which a dangling link has none of.
     *
     * @return array<int|string, int>|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        $link = ($flags & STREAM_URL_STAT_LINK) !== 0;

        return $this->natively(static fn(): array|false => match (true) {
            file_exists($path) => $link ? lstat($path) : stat($path),
            $link && is_link($path) => lstat($path),
            default => false,
        });
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
        $handle = $this->natively(static fn(): mixed => is_dir($path) ? opendir($path) : false);

        return is_resource($handle) && $this->opened($handle, served: false);
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

    /**
     * Holds what PHP's own wrapper opened, and, where it is the mutated file
     * served, says so in the guard file.
     *
     * @param resource $handle
     */
    private function opened(mixed $handle, bool $served): bool
    {
        $this->handle = $handle;
        $this->drained = false;

        if ($served) {
            $this->natively(static fn(): int|false => file_put_contents(
                self::$guard,
                sprintf("%s\n", self::SERVED),
                FILE_APPEND | LOCK_EX,
            ));
        }

        return true;
    }

    /** Whether a path PHP includes names the file served: the same device and inode, whatever the path's spelling. */
    private function isServed(string $path): bool
    {
        $stat = is_file($path) ? stat($path) : false;

        return is_array($stat) && [$stat['dev'], $stat['ino']] === self::$served;
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
