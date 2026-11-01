<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use function file_get_contents;
use function is_file;

use NightWorksIO\MutationGate\Core\Composer\Installed as InstalledPackages;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\ErrorDisplay;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitConfig;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitIni;

use function realpath;
use function sprintf;

/**
 * PHPUnit as a project installed it: the project's root, and the vendor directory it is in.
 *
 * @internal
 */
final readonly class Installation
{
    /** PHPUnit's script, in the vendor directory's `bin`. */
    private const string PHPUNIT = 'bin/phpunit';

    private function __construct(private Root $root, private Path $vendor)
    {
    }

    /** PHPUnit in the project at a directory, held as its real path, or as it is named where it is not there. */
    public static function at(string $root, Path $vendor): self
    {
        $real = realpath($root);

        return new self(Root::of($real === false ? $root : $real), $vendor);
    }

    /** PHPUnit in a directory of this project, its vendor directory named as here. */
    public function in(Path $directory): self
    {
        return self::at($this->absolute($directory), $this->vendor);
    }

    /** Where a path of the project is on disk. */
    public function absolute(Path $path): string
    {
        return $this->root->at($path)->value();
    }

    public function phpunit(): string
    {
        return $this->absolute($this->vendor->child(Path::of(self::PHPUNIT)));
    }

    /** Whether PHPUnit's script is in the project's vendor directory, so the project holds a PHPUnit to run. */
    public function hasPhpUnit(): bool
    {
        return is_file($this->phpunit());
    }

    /** Where Composer lists what it installed in the project's vendor directory. */
    public function installed(): string
    {
        return $this->absolute(InstalledPackages::fileIn($this->vendor));
    }

    /** The PHPUnit config PHPUnit reads in the root, by its absolute path; none where the project has none. */
    public function config(): string|NotGiven
    {
        foreach (PhpUnitConfig::candidatesIn(Path::root()) as $candidate) {
            if (is_file($this->absolute($candidate))) {
                return $this->absolute($candidate);
            }
        }

        return NotGiven::value();
    }

    /**
     * Where the PHPUnit config PHPUnit reads in the root has PHP print
     * errors, as its `<php>` sets `display_errors`; none where it sets none,
     * or the project has no config.
     */
    public function errorDisplay(): ErrorDisplay|NotGiven
    {
        $config = $this->config();

        return $config instanceof NotGiven
            ? $config
            : PhpUnitIni::displayIn(sprintf('%s', file_get_contents($config)));
    }
}
