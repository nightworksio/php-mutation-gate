<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function is_string;
use function mb_strtolower;

/**
 * Whether opcache could serve a cached original of a file in place of the
 * mutated copy an override serves: on for the command line, or keeping a
 * file cache.
 */
final readonly class Opcache
{
    /** PHP's switch of opcache for the command line. */
    public const string CLI = 'opcache.enable_cli';

    /** PHP's setting of the directory opcache keeps its file cache in, empty for none. */
    public const string FILE_CACHE = 'opcache.file_cache';

    private function __construct(private bool $on)
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

    public function couldServeTheOriginal(): bool
    {
        return $this->on;
    }
}
