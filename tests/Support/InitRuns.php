<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function chdir;
use function file_get_contents;
use function is_file;
use function sprintf;

/** `init` run in a test's copy of a fixture project, and the files it leaves there. */
final readonly class InitRuns
{
    /**
     * `init` run in a copy of the Laravel fixture, with these files written into it first.
     *
     * @param  array<string, string|bool|null> $input
     * @param  array<string, string>           $files
     * @return list{string, Commands}
     */
    public static function inLaravel(array $input, array $files = []): array
    {
        $project = Scratch::copy('tests/Fixtures/Projects/Laravel');

        foreach ($files as $path => $text) {
            Scratch::write($project, $path, $text);
        }

        chdir($project);
        $ran = Commands::run($project, 'init', ['--no-measure' => true, ...$input]);

        return [$project, $ran];
    }

    /** A file of a project, or '' when it is not there. */
    public static function file(string $project, string $path): string
    {
        $file = sprintf('%s/%s', $project, $path);

        return is_file($file) ? (string) file_get_contents($file) : '';
    }
}
