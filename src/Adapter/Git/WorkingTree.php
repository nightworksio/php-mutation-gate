<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Git;

use function array_filter;
use function array_map;
use function array_pop;
use function array_values;
use function explode;
use function file_get_contents;
use function implode;
use function is_file;
use function is_link;
use function is_string;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Fingerprint;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;

use function readlink;
use function sprintf;
use function str_contains;
use function trim;

/**
 * The files of a repository's working tree as git sees them on disk: what
 * each holds, and the blob id git gives it. A link is what git stores for
 * it, the path it points to, and never what it points to, which may lie
 * outside the repository.
 */
final readonly class WorkingTree
{
    /** Why a path cannot go to git in a batch of them. */
    private const string LINE_BREAK = 'git cannot hash "%s" with the others, because its name holds a line break.';

    private function __construct(private Command $git, private Root $directory)
    {
    }

    public static function of(Command $git, Root $directory): self
    {
        return new self($git, $directory);
    }

    /**
     * Every listed path that is a file or a link on disk, with its blob id.
     *
     * @param list<string> $listed
     */
    public function fingerprints(array $listed): Fingerprints|CannotTell
    {
        return $this->hashed($this->onDisk($listed));
    }

    /**
     * What a file on disk holds, or that it is missing where it is no file or
     * cannot be read. A link holds the path it points to, as git diffs it.
     */
    public function read(Path $path): Contents|Missing
    {
        $file = $this->directory->at($path)->value();
        $text = match (true) {
            is_link($file) => readlink($file),
            is_file($file) => file_get_contents($file),
            default => false,
        };

        return is_string($text) ? Contents::of($text) : Missing::at($path);
    }

    /**
     * The paths that are files or links on disk.
     *
     * @param  list<string>       $paths
     * @return array<int, string>
     */
    private function onDisk(array $paths): array
    {
        return array_filter($paths, function (string $path): bool {
            $file = $this->onDiskAt($path);

            return is_link($file) || is_file($file);
        });
    }

    /**
     * Every file with the blob id git gives what it holds on disk, the files
     * hashed in one batch. A link is hashed as git stores it, as the path it
     * points to, and never as what it points to, which may lie outside the
     * repository.
     *
     * @param array<int, string> $paths
     */
    private function hashed(array $paths): Fingerprints|CannotTell
    {
        $files = array_values(array_filter($paths, fn(string $path): bool => ! is_link($this->onDiskAt($path))));
        $links = array_values(array_filter($paths, fn(string $path): bool => is_link($this->onDiskAt($path))));
        $input = $this->input($files);
        $hashed = match (true) {
            $files === [] => '',
            $input instanceof CannotTell => $input,
            default => $this->git->feed(['hash-object', '--no-filters', '--stdin-paths'], $input),
        };
        $linked = $this->linksHashed($links);

        return match (true) {
            $hashed instanceof CannotTell => $hashed,
            $linked instanceof CannotTell => $linked,
            default => Fingerprints::of(...$this->fingerprinted($files, $this->lines($hashed)), ...$linked),
        };
    }

    /**
     * Each link with the blob id of the path it points to.
     *
     * @param  list<string>            $links
     * @return list<Fingerprint>|CannotTell
     */
    private function linksHashed(array $links): array|CannotTell
    {
        $hashed = [];

        foreach ($links as $link) {
            $digest = $this->git->feed(['hash-object', '--stdin'], $this->linkTarget($link));

            if ($digest instanceof CannotTell) {
                return $digest;
            }

            $hashed[] = Fingerprint::of(Path::of($link), Digest::of(trim($digest)));
        }

        return $hashed;
    }

    /**
     * @param  list<string>      $paths
     * @param  list<string>      $digests one per path, in their order
     * @return list<Fingerprint>
     */
    private function fingerprinted(array $paths, array $digests): array
    {
        return array_map(
            static fn(string $path, string $digest): Fingerprint => Fingerprint::of(
                Path::of($path),
                Digest::of($digest),
            ),
            $paths,
            $digests,
        );
    }

    private function onDiskAt(string $path): string
    {
        return $this->directory->at(Path::of($path))->value();
    }

    /**
     * The paths as git reads them from its input, one per line.
     *
     * @param array<int, string> $paths
     */
    private function input(array $paths): string|CannotTell
    {
        foreach ($paths as $path) {
            if (str_contains($path, "\n")) {
                return CannotTell::because(sprintf(self::LINE_BREAK, $path));
            }
        }

        return sprintf("%s\n", implode("\n", $paths));
    }

    /**
     * The lines of what git printed, each ended by a line break.
     *
     * @return list<string>
     */
    private function lines(string $printed): array
    {
        $lines = explode("\n", $printed);
        array_pop($lines);

        return $lines;
    }

    /** The path a link points to, as git stores it. */
    private function linkTarget(string $link): string
    {
        $target = readlink($this->onDiskAt($link));

        return $target === false ? '' : $target;
    }
}
