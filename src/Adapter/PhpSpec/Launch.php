<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpSpec;

use Composer\InstalledVersions;

use function dirname;
use function getcwd;

use NightWorksIO\MutationGate\Core\Composer\Package;
use NightWorksIO\MutationGate\Core\Native\OwnFiles;
use PhpSpec\Console\Application;
use Symfony\Component\Console\Input\ArgvInput;

/**
 * PhpSpec as the gate's launcher starts it, in place of PhpSpec's own
 * script: PhpSpec's console, with the gate's extension loaded into its
 * container before PhpSpec reads the project's config, run on PhpSpec's own
 * arguments, its exit code what it says.
 */
final readonly class Launch
{
    /**
     * @param string       $script     the launcher's own script, as its command line names it
     * @param string       $autoloader the project's autoloader, in its vendor directory
     * @param list<string> $arguments  the launcher's command line after the project's autoloader
     */
    public static function run(string $script, string $autoloader, array $arguments): int
    {
        $told = Told::from($arguments);
        $own = OwnFiles::under((string) getcwd(), dirname($autoloader));
        $phpspec = new Application((string) InstalledVersions::getPrettyVersion(Package::PhpSpec->value));
        new Extension($told, $own)->load($phpspec->getContainer(), []);
        $phpspec->setAutoExit(boolean: false);

        return $phpspec->run(new ArgvInput([$script, ...$told->arguments()]));
    }
}
