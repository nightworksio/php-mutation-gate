<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpSpec;

use function array_filter;
use function array_values;
use function realpath;
use function sprintf;
use function str_starts_with;

/**
 * The project's own files among those PHP loaded: those under its root but
 * outside its vendor directory, as pcov collects from them where the native
 * runner sets it (see PcovReach), so a coverage map measured with any driver
 * reaches the same files.
 */
final readonly class OwnFiles
{
    private const string WITHIN = '%s/';

    private function __construct(private string $root, private string $vendor)
    {
    }

    /** Under a project's root, less its vendor directory, each by its real path where it is there. */
    public static function under(string $root, string $vendor): self
    {
        return new self(self::directory($root), self::directory($vendor));
    }

    /**
     * @param list<string> $loaded files by their real paths, as PHP lists those it loaded
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

    private static function directory(string $directory): string
    {
        $real = realpath($directory);

        return sprintf(self::WITHIN, $real === false ? $directory : $real);
    }
}
