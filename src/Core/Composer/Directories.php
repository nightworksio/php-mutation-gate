<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Composer;

use NightWorksIO\MutationGate\Core\File\Path;

/**
 * Where a manifest's `config` has Composer install packages and link their
 * commands: `config.vendor-dir`, or `vendor`, and `config.bin-dir`, as it is
 * written, or nothing where it names none.
 */
final readonly class Directories
{
    private function __construct(private string $vendor, private string $bin)
    {
    }

    /** The directories as `config.vendor-dir` and `config.bin-dir` write them, each empty where it names none. */
    public static function declared(string $vendor, string $bin): self
    {
        return new self($vendor, $bin);
    }

    /** Where Composer installs the project's packages: `config.vendor-dir`, or `vendor`. */
    public function vendor(): Path
    {
        return Path::of($this->vendor === '' ? Manifest::VENDOR : $this->vendor);
    }

    /** Where Composer links the commands of installed packages, as `config.bin-dir` writes it, or nothing. */
    public function bin(): string
    {
        return $this->bin;
    }
}
