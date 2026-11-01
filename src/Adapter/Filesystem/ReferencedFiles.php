<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Filesystem;

use function array_filter;
use function array_values;

use FilesystemIterator;

use function hash_update_file;
use function is_dir;
use function is_file;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\NotGiven;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The contents of the files a static analyser's configuration references
 * (ADR-0020), each by its digest: a file referenced, and every
 * file under a directory referenced, found without following a linked
 * directory. Each is named as the project spells it, under its root, so a
 * digest of them is the same on every machine; a file outside the root keeps
 * the path it was named by. One that is not there, or cannot be read, is
 * missing. The analyser reads these files itself, wherever they are, so they
 * are read wherever they are. Where version control lists the project's
 * files, a file under the root it does not list, as it ignores a framework's
 * generated cache, is none of them, as no source key reads one (ADR-0020).
 *
 * @internal
 */
final readonly class ReferencedFiles
{
    private function __construct(private Root $root, private Paths|NotGiven $listed)
    {
    }

    public static function under(Root $root): self
    {
        return new self($root, NotGiven::value());
    }

    /** The references under the root, of which version control can say which it lists. */
    public static function inside(Paths $references): Paths
    {
        return Paths::of(...array_filter([...$references], self::isInside(...)));
    }

    /** These files, of those under the root only those version control lists, where it can say. */
    public function listing(Paths|CannotTell $listed): self
    {
        return $listed instanceof Paths ? new self($this->root, $listed) : $this;
    }

    /**
     * The digest of each referenced file, and of each file under a
     * referenced directory, by its path.
     *
     * @return ByPath<Digest|Missing>
     */
    public function digests(Paths $references): ByPath
    {
        $digests = ByPath::none();

        foreach ($references as $reference) {
            foreach ($this->filesOf($reference) as $file) {
                $digests = $digests->with($file, $this->digestOf($file));
            }
        }

        return $digests;
    }

    /** @return list<Path> the file referenced, or every file under the directory referenced, less those left out */
    private function filesOf(Path $reference): array
    {
        $at = $this->root->at($reference)->value();

        return array_values(array_filter(
            is_dir($at) ? $this->filesUnder($at) : [$reference],
            fn(Path $file): bool => ! $this->leavesOut($file),
        ));
    }

    /** @return list<Path> every file under a directory, as the project spells it */
    private function filesUnder(string $directory): array
    {
        $files = [];
        $found = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($found as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $files[] = $this->root->relative($file->getPathname());
            }
        }

        return $files;
    }

    private static function isInside(Path $path): bool
    {
        return ! $path->isAbsolute() && ! $path->escapes();
    }

    private function leavesOut(Path $file): bool
    {
        return $this->listed instanceof Paths && self::isInside($file) && ! $this->listed->has($file);
    }

    private function digestOf(Path $file): Digest|Missing
    {
        $at = $this->root->at($file)->value();
        $hashing = Digest::hashing();

        return is_file($at) && hash_update_file($hashing, $at) ? Digest::finished($hashing) : Missing::at($file);
    }
}
