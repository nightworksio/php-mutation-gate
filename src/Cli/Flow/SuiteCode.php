<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Php\PhpFile;

/**
 * The code of the suite's files, each read once for the holds the inventory
 * reads and handed on to the name graph a key reads, which takes it whole and
 * leaves none held: a file's tokens are large, and the graph keeps none of
 * them.
 *
 * @internal
 */
final class SuiteCode
{
    /** @var array<string, PhpFile> each file read so far, by its path */
    private array $read = [];

    /** A file's code, read from what it holds the first time it is asked for. */
    public function of(Path $path, Contents $contents): PhpFile
    {
        $key = $path->value();

        if (! array_key_exists($key, $this->read)) {
            $this->read[$key] = PhpFile::read($contents);
        }

        return $this->read[$key];
    }

    /**
     * Every file read so far, taken: each is read again where it is asked
     * for after.
     *
     * @return array<string, PhpFile> by path
     */
    public function taken(): array
    {
        $taken = $this->read;
        $this->read = [];

        return $taken;
    }
}
