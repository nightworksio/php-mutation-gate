<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Codeception;

use function is_file;

use NightWorksIO\MutationGate\Core\Composer\Installed as InstalledPackages;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;

use function realpath;

/** Codeception as a project installed it: the project's root, and the vendor directory it is in. */
final readonly class Installation
{
    /** Codeception's script, in the vendor directory's `bin`. */
    private const string CODECEPT = 'bin/codecept';

    private function __construct(private Root $root, private Path $vendor)
    {
    }

    /** Codeception in the project at a directory, held as its real path, or as it is named where it is not there. */
    public static function at(string $root, Path $vendor): self
    {
        $real = realpath($root);

        return new self(Root::of($real === false ? $root : $real), $vendor);
    }

    /** Codeception in a directory of this project, its vendor directory named as here. */
    public function in(Path $directory): self
    {
        return self::at($this->absolute($directory), $this->vendor);
    }

    /** Where a path of the project is on disk. */
    public function absolute(Path $path): string
    {
        return $this->root->at($path)->value();
    }

    /** Whether Codeception's script is in the project's vendor directory, so the project holds a Codeception to run. */
    public function hasCodeception(): bool
    {
        return is_file($this->codecept());
    }

    /** Codeception's script, which a mutant's run starts. */
    public function codecept(): string
    {
        return $this->absolute($this->vendor->child(Path::of(self::CODECEPT)));
    }

    /** The project's vendor directory, on disk. */
    public function vendor(): string
    {
        return $this->absolute($this->vendor);
    }

    /** Where Composer lists what it installed in the project's vendor directory. */
    public function installed(): string
    {
        return $this->absolute(InstalledPackages::fileIn($this->vendor));
    }
}
