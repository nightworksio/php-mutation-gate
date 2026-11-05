<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use Closure;
use NightWorksIO\MutationGate\Adapter\Filesystem\PackageDirectory;
use NightWorksIO\MutationGate\Core\Composer\PackageFiles;
use NightWorksIO\MutationGate\Core\NotGiven;

/** The packages' files on disk, refusing to write the files this picks out. */
final readonly class RefusingPackageFiles implements PackageFiles
{
    /** @param Closure(string): bool $refuses whether the file at a path is left unwritten */
    public function __construct(private Closure $refuses, private PackageFiles $disk = new PackageDirectory())
    {
    }

    public function read(string $path): string|NotGiven
    {
        return $this->disk->read($path);
    }

    public function every(string $directory): array
    {
        return $this->disk->every($directory);
    }

    public function isWritable(string $path): bool
    {
        return $this->disk->isWritable($path);
    }

    public function written(string $path, string $contents): bool
    {
        return ! ($this->refuses)($path) && $this->disk->written($path, $contents);
    }
}
