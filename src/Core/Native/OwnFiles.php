<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Native;

use function array_filter;
use function array_values;

use NightWorksIO\MutationGate\Core\File\Path;

use function sprintf;
use function str_starts_with;

/**
 * The project's own files among those PHP loaded: those under its root but
 * outside its vendor directory, as pcov collects from them where the native
 * runner sets it (see PcovReach), so a coverage map a framework's extension
 * measures with any driver reaches the same files.
 */
final readonly class OwnFiles
{
    private const string WITHIN = '%s/';

    private function __construct(private string $root, private string $vendor)
    {
    }

    /** Under a project's root, less its vendor directory, each absolute, with each `..` taken back. */
    public static function under(string $root, string $vendor): self
    {
        return new self(self::within($root), self::within($vendor));
    }

    /**
     * @param array<array-key, string> $loaded files, as PHP lists those it loaded
     *
     * @return list<string>
     */
    public function among(array $loaded): array
    {
        return array_values(array_filter(
            $loaded,
            fn(string $file): bool => str_starts_with($file, $this->root) && ! str_starts_with($file, $this->vendor),
        ));
    }

    /** What a path inside a directory starts with: the directory as PHP lists a file it loaded from it. */
    private static function within(string $directory): string
    {
        return sprintf(self::WITHIN, Path::of($directory)->collapsed()->value());
    }
}
