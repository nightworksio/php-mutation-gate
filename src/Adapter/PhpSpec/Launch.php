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
 * container before PhpSpec reads the project's config, and PhpSpec's own
 * arguments, which it runs on and exits with what it says.
 *
 * @internal
 */
final readonly class Launch
{
    private function __construct(private Told $told, private string $autoloader)
    {
    }

    /**
     * @param string       $autoloader the project's autoloader, in its vendor directory
     * @param list<string> $arguments  the launcher's command line after the project's autoloader
     */
    public static function of(string $autoloader, array $arguments): self
    {
        return new self(Told::from($arguments), $autoloader);
    }

    /** PhpSpec's console, the gate's extension loaded into its container. */
    public function phpspec(): Application
    {
        $phpspec = new Application((string) InstalledVersions::getPrettyVersion(Package::PhpSpec->value));
        new Extension($this->told, OwnFiles::under((string) getcwd(), dirname($this->autoloader)))
            ->load($phpspec->getContainer(), []);

        return $phpspec;
    }

    /** PhpSpec's own arguments, with the gate's taken out, behind the launcher's script. */
    public function input(string $script): ArgvInput
    {
        return new ArgvInput([$script, ...$this->told->arguments()]);
    }
}
