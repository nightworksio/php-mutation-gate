<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use function in_array;
use function ini_get;
use function is_string;
use function mb_strtolower;

/**
 * Whether opcache could serve a cached original of a file in place of the
 * mutated copy Pest's override serves: on for the command line, or keeping a
 * file cache.
 */
final readonly class Opcache
{
    /** How PHP writes an ini switch that is on. */
    private const array ON = ['1', 'on', 'true', 'yes'];

    private function __construct(private bool $on)
    {
    }

    /** As this PHP is set: `opcache.enable_cli` and `opcache.file_cache`, each false where opcache is not loaded. */
    public static function current(): self
    {
        return self::of(ini_get('opcache.enable_cli'), ini_get('opcache.file_cache'));
    }

    public static function of(string|false $cli, string|false $fileCache): self
    {
        $cached = is_string($cli) && in_array(mb_strtolower($cli), self::ON, strict: true)
            || is_string($fileCache) && $fileCache !== '';

        return new self($cached);
    }

    public function couldServeTheOriginal(): bool
    {
        return $this->on;
    }
}
