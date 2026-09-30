<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Check;

use function is_string;

use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\RunnerPhp;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Runner\Opcache as Cache;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

use function sprintf;

/**
 * OPcache on for the command line, or keeping a file cache, which could serve
 * a file's original in place of its mutant, so a mutant judged by reference
 * is left unjudged (ADR-0004, decision 8).
 */
final readonly class Opcache
{
    private const string CLI = 'opcache.enable_cli';

    private const string FILE_CACHE = 'opcache.file_cache';

    private const string FOUND
        = 'The PHP the runner runs its tests on, %s, has opcache.enable_cli on or keeps an opcache.file_cache.';

    private const string WHY
        = 'OPcache could serve a file\'s original in place of its mutant, so mutants judged by reference go unjudged.';

    private const string FIX = 'Set opcache.enable_cli=0 and opcache.file_cache= in %s.';

    public static function in(Observations $observed): Findings
    {
        $php = $observed->php();

        if (! $php instanceof RunnerPhp || ! self::cached($php)) {
            return Findings::none();
        }

        return Findings::of(Finding::of(
            Slug::OpcacheOnTheCommandLine,
            Severity::WillFail,
            sprintf(self::FOUND, $php->binary()),
            self::WHY,
            sprintf(self::FIX, $php->iniFile()),
        ));
    }

    private static function cached(RunnerPhp $php): bool
    {
        return Cache::of(self::switch($php, self::CLI), self::switch($php, self::FILE_CACHE))->couldServeTheOriginal();
    }

    /** A setting as `ini_get` answers it: false where the PHP has no such setting. */
    private static function switch(RunnerPhp $php, string $setting): string|false
    {
        $value = $php->valueOf($setting);

        return is_string($value) ? $value : false;
    }
}
