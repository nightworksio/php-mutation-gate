<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Filesystem;

use FilesystemIterator;

use function hash_update_file;
use function is_dir;
use function is_file;

use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The contents of the files a static analyser's configuration references
 * (ADR-0020, decision 14), each by its digest: a file referenced, and every
 * file under a directory referenced, found without following a linked
 * directory. Each is named as the project spells it, under its root, so a
 * digest of them is the same on every machine; a file outside the root keeps
 * the path it was named by. One that is not there, or cannot be read, is
 * missing. The analyser reads these files itself, wherever they are, so they
 * are read wherever they are.
 */
final readonly class ReferencedFiles
{
    private function __construct(private Root $root)
    {
    }

    public static function under(Root $root): self
    {
        return new self($root);
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
            $at = $this->root->at($reference)->value();

            foreach (is_dir($at) ? $this->filesUnder($at) : [$reference] as $file) {
                $digests = $digests->with($file, $this->digestOf($file));
            }
        }

        return $digests;
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

    private function digestOf(Path $file): Digest|Missing
    {
        $at = $this->root->at($file)->value();
        $hashing = Digest::hashing();

        return is_file($at) && hash_update_file($hashing, $at) ? Digest::finished($hashing) : Missing::at($file);
    }
}
