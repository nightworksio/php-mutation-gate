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
 * is left unjudged (ADR-0004, decision 8). A runner that turns opcache off on
 * each mutant's command line is never served a cached original (ADR-0023,
 * decision 9).
 */
final readonly class Opcache
{
    private const string FOUND
        = 'The PHP the runner runs its tests on, %s, has %s on or keeps an %s.';

    private const string WHY
        = 'OPcache could serve a file\'s original in place of its mutant, so mutants judged by reference go unjudged.';

    private const string FIX = 'Set %s=0 and %s= in %s.';

    public static function in(Observations $observed): Findings
    {
        $php = $observed->php();

        if (! $php instanceof RunnerPhp || $php->turnsOpcacheOff() || ! self::cached($php)) {
            return Findings::none();
        }

        return Findings::of(Finding::of(
            Slug::OpcacheOnTheCommandLine,
            Severity::WillFail,
            sprintf(self::FOUND, $php->binary(), Cache::CLI, Cache::FILE_CACHE),
            self::WHY,
            sprintf(self::FIX, Cache::CLI, Cache::FILE_CACHE, $php->iniFile()),
        ));
    }

    private static function cached(RunnerPhp $php): bool
    {
        $cache = Cache::of(self::switch($php, Cache::CLI), self::switch($php, Cache::FILE_CACHE));

        return $cache->couldServeTheOriginal();
    }

    /** A setting as `ini_get` answers it: false where the PHP has no such setting. */
    private static function switch(RunnerPhp $php, string $setting): string|false
    {
        $value = $php->valueOf($setting);

        return is_string($value) ? $value : false;
    }
}
