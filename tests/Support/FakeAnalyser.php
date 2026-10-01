<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function chmod;
use function glob;

use const PHP_BINARY;

use function sprintf;

/**
 * A project with a stand-in for an analyser's command: a PHP script that
 * says the version it is given for `--version`, lists the files it is given
 * for `list-files`, dumps the parameters it is given for `dump-parameters`,
 * or else `src` under the project as PHPStan's one path, and otherwise writes the answer it is given and exits as
 * told, keeping the arguments it was run with in `argv.txt` beside it.
 */
final readonly class FakeAnalyser
{
    private const string SCRIPT = <<<'PHP'
        <?php
        $arguments = array_slice($argv, 1);
        $here = __DIR__;
        $said = static fn(string $name): string => (string) @file_get_contents(sprintf('%s/%s', $here, $name));
        $leaked = getenv('MUTATION_GATE_CONTRACT_TOKEN') !== false ? 'leaked' : 'withheld';
        file_put_contents(sprintf('%s/argv.txt', $here), implode("\n", [...$arguments, $leaked]));
        if (in_array('--version', $arguments, true)) {
            echo $said('version.txt');
            exit(0);
        }
        if (in_array('dump-parameters', $arguments, true)) {
            $project = dirname($here, 2);
            $default = sprintf('{"paths": ["%s/src"], "excludePaths": {"analyseAndScan": [], "analyse": []}}', $project);
            echo is_file(sprintf('%s/params.json', $here)) ? $said('params.json') : $default;
            exit((int) $said('params.exit'));
        }
        if (in_array('list-files', $arguments, true)) {
            echo $said('files.txt');
            exit((int) $said('files.exit'));
        }
        echo $said('answer.json');
        fwrite(STDERR, $said('answer.err'));
        exit((int) $said('answer.exit'));
        PHP;

    /** A project whose `vendor/bin/phpstan` is the stand-in, with a phpstan.neon, saying this version. */
    public static function phpstan(string $version): string
    {
        $project = Scratch::directory();
        Scratch::write($project, 'vendor/bin/phpstan', self::SCRIPT);
        Scratch::write($project, 'vendor/bin/version.txt', $version);
        Scratch::write($project, 'phpstan.neon', "parameters:\n    level: 9\n");

        return $project;
    }

    /**
     * A project whose vendor directory holds Mago as its package downloaded it, as the stand-in's
     * executable, saying this version, and listing these files as analysed.
     */
    public static function mago(string $version, string $files): string
    {
        $project = Scratch::directory();
        $bin = sprintf('vendor/carthage-software/mago/composer/bin/%1$s/mago-%1$s-test', $version);
        Scratch::write($project, 'vendor/composer/installed.json', sprintf(
            '{"packages": [{"name": "carthage-software/mago", "version": "%s"}]}',
            $version,
        ));
        Scratch::write($project, sprintf('%s/fake.php', $bin), self::SCRIPT);
        Scratch::write($project, sprintf('%s/mago', $bin), sprintf("#!/bin/sh\nexec %s \"$(dirname \"$0\")/fake.php\" \"$@\"\n", PHP_BINARY));
        chmod(sprintf('%s/%s/mago', $project, $bin), 0o755);
        Scratch::write($project, sprintf('%s/version.txt', $bin), sprintf('mago %s', $version));
        Scratch::write($project, sprintf('%s/files.txt', $bin), $files);
        Scratch::write($project, sprintf('%s/files.exit', $bin), '0');

        return $project;
    }

    /** Where the stand-in keeps what it answers and what it was run with, in a project made above. */
    public static function scripts(string $project): string
    {
        $found = glob(sprintf('%s/vendor/carthage-software/mago/composer/bin/*/mago-*', $project));

        return $found === false || $found === [] ? sprintf('%s/vendor/bin', $project) : $found[0];
    }
}
