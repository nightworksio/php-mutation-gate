<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpSpec;

use function is_file;

use NightWorksIO\MutationGate\Core\Composer\Autoloader;
use NightWorksIO\MutationGate\Core\Composer\Installed as InstalledPackages;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;

use function realpath;

/** PhpSpec as a project installed it: the project's root, and the vendor directory it is in. */
final readonly class Installation
{
    /** PhpSpec's script, in the vendor directory's `bin`. */
    private const string PHPSPEC = 'bin/phpspec';

    private function __construct(private Root $root, private Path $vendor)
    {
    }

    /** PhpSpec in the project at a directory, held as its real path, or as it is named where it is not there. */
    public static function at(string $root, Path $vendor): self
    {
        $real = realpath($root);

        return new self(Root::of($real === false ? $root : $real), $vendor);
    }

    /** PhpSpec in a directory of this project, its vendor directory named as here. */
    public function in(Path $directory): self
    {
        return self::at($this->absolute($directory), $this->vendor);
    }

    /** Where a path of the project is on disk. */
    public function absolute(Path $path): string
    {
        return $this->root->at($path)->value();
    }

    /** Whether PhpSpec's script is in the project's vendor directory, so the project holds a PhpSpec to run. */
    public function hasPhpSpec(): bool
    {
        return is_file($this->absolute($this->vendor->child(Path::of(self::PHPSPEC))));
    }

    /** Composer's autoloader in the project's vendor directory, which the launcher loads PhpSpec with. */
    public function autoloader(): string
    {
        return $this->absolute(Autoloader::fileIn($this->vendor));
    }

    /** Where Composer lists what it installed in the project's vendor directory. */
    public function installed(): string
    {
        return $this->absolute(InstalledPackages::fileIn($this->vendor));
    }
}
