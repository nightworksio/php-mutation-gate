<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Warm;

use function array_filter;
use function array_map;
use function array_values;
use function file;

use const FILE_IGNORE_NEW_LINES;

use function file_put_contents;
use function implode;
use function is_array;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;

use function realpath;
use function sprintf;

/**
 * What a run's warm workers keep out of opcache, where they compile through
 * it (ADR-0088): each file the run mutates, the folder pest-plugin-mutate
 * writes its mutated copies to, and the gate's own directory, which holds
 * the unmutated copies it serves. No mutant, and no original a mutant is
 * served in place of, is ever cached, so no run is served what another
 * compiled; every other file compiles once for every run the workers fork. A worker whose directory
 * holds none runs with opcache off.
 *
 * @internal
 */
final readonly class OpcacheBlacklist
{
    /** The file in a run's warm directory that names what opcache keeps out. */
    private const string FILE = '%s/opcache-blacklist';

    /** Where pest-plugin-mutate writes each mutated copy, under the vendor directory. */
    private const string COPIES = '%s/pestphp/pest-plugin-mutate/.temp';

    /** @param list<string> $paths */
    private function __construct(private array $paths)
    {
    }

    /** What the workers of a run of this request keep out of opcache. */
    public static function of(Project $project, MutationRequest $request): self
    {
        return new self([
            ...array_map(static fn(Path $file): string => self::resolved($project->absolute($file)), [
                ...$request->files(),
            ]),
            self::resolved(sprintf(self::COPIES, $project->absolute($project->vendor()))),
            self::resolved($project->workspace()),
        ]);
    }

    /**
     * The options a worker in this directory starts PHP with: opcache on,
     * keeping out what the directory's blacklist names; none where it holds
     * no blacklist, and the worker runs with opcache off.
     *
     * @return list<string>
     */
    public static function options(string $directory): array
    {
        $file = sprintf(self::FILE, $directory);

        return is_file($file)
            ? ['-d', 'opcache.enable_cli=1', '-d', sprintf('opcache.blacklist_filename=%s', $file)]
            : [];
    }

    /** Whether the workers in this directory compile through opcache. */
    public static function isIn(string $directory): bool
    {
        return is_file(sprintf(self::FILE, $directory));
    }

    /**
     * The paths a blacklist file names, as PHP's setting names the file; none where it names none, or none is there.
     *
     * @return list<string>
     */
    public static function named(string|false $file): array
    {
        $lines = is_string($file) && is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : [];

        return is_array($lines)
            ? array_values(array_filter($lines, static fn(string $line): bool => $line !== ''))
            : [];
    }

    /** It, left in a run's warm directory for the workers that start there. */
    public function leftIn(string $directory): void
    {
        file_put_contents(sprintf(self::FILE, $directory), sprintf("%s\n", implode("\n", $this->paths)));
    }

    /** A path as opcache keys the files it caches, resolved where it is there, and as given where it is not. */
    private static function resolved(string $path): string
    {
        $real = realpath($path);

        return is_string($real) ? $real : $path;
    }
}
