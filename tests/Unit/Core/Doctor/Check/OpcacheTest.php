<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Doctor\Check\Opcache;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\RunnerPhp;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

$cached = Findings::of(Finding::of(
    Slug::OpcacheOnTheCommandLine,
    Severity::WillFail,
    'The PHP the runner runs its tests on, /usr/bin/php, has opcache.enable_cli on or keeps an opcache.file_cache.',
    'OPcache could serve a file\'s original in place of its mutant, so mutants judged by reference go unjudged.',
    'Set opcache.enable_cli=0 and opcache.file_cache= in the php.ini this PHP loads.',
));

it('finds OPcache that could serve an original in place of its mutant', function (string $cli, string $fileCache) use ($cached): void {
    $php = RunnerPhp::at('/usr/bin/php')->setting('opcache.enable_cli', $cli)->setting('opcache.file_cache', $fileCache);

    expect(Opcache::in(Observations::none()->withPhp($php)))->toEqual($cached);
})->with([
    'on for the command line' => ['On', ''],
    'keeping a file cache' => ['Off', '/tmp/opcache'],
]);

it('finds nothing where OPcache is off, not loaded, or the PHP was not observed', function (): void {
    $off = RunnerPhp::at('php')->setting('opcache.enable_cli', 'Off')->setting('opcache.file_cache', '');

    expect(Opcache::in(Observations::none()->withPhp($off)))->toEqual(Findings::none())
        ->and(Opcache::in(Observations::none()->withPhp(RunnerPhp::at('php'))))->toEqual(Findings::none())
        ->and(Opcache::in(Observations::none()))->toEqual(Findings::none());
});
