<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function array_any;
use function array_filter;
use function array_values;
use function is_string;
use function mb_strtolower;
use function str_starts_with;

/**
 * Whether opcache could serve a cached original of a file in place of the
 * mutated copy an override serves: on for the command line, or keeping a
 * file cache.
 *
 * @internal
 */
final readonly class Opcache
{
    /** PHP's switch of opcache for the command line. */
    public const string CLI = 'opcache.enable_cli';

    /** PHP's setting of the directory opcache keeps its file cache in, empty for none. */
    public const string FILE_CACHE = 'opcache.file_cache';

    /** PHP's setting of the file that names what opcache keeps out, empty for none. */
    public const string BLACKLIST = 'opcache.blacklist_filename';

    /** @param list<string> $keptOut the paths opcache keeps out, each a prefix of the files it names */
    private function __construct(private bool $on, private array $keptOut = [])
    {
    }

    /** As a PHP sets `opcache.enable_cli` and `opcache.file_cache`, each false where opcache is not loaded. */
    public static function of(string|false $cli, string|false $fileCache): self
    {
        $cached = is_string($cli) && OnSwitch::tryFrom(mb_strtolower($cli)) instanceof OnSwitch
            || is_string($fileCache) && $fileCache !== '';

        return new self($cached);
    }

    /**
     * As a PHP sets `opcache.enable_cli`, false where opcache is not loaded,
     * where the gate turns it off for the command line: opcache then does
     * not run, and uses no file cache either.
     */
    public static function ofCommandLine(string|false $cli): self
    {
        return self::of($cli, fileCache: false);
    }

    /** The same, keeping out the files these paths begin, as a blacklist names them (ADR-0088). */
    public function keepingOut(string ...$paths): self
    {
        return new self($this->on, array_values(array_filter($paths, static fn(string $path): bool => $path !== '')));
    }

    public function couldServeTheOriginal(): bool
    {
        return $this->on;
    }

    /** Whether it could serve a cached original of this file: it is on, and keeps none of the file out. */
    public function couldServe(string $original): bool
    {
        return $this->on && ! array_any(
            $this->keptOut,
            static fn(string $path): bool => str_starts_with($original, $path),
        );
    }
}
