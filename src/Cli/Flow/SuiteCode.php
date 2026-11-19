<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Php\NamedFiles;
use NightWorksIO\MutationGate\Core\Php\PhpFile;

/**
 * The code of the suite's files, each read for the holds the inventory reads.
 * Where a key will read the name graph, it keeps what each file declares and
 * mentions, not its tokens, and hands that on to the graph, which takes it
 * and leaves none kept; elsewhere it keeps nothing.
 *
 * @internal
 */
final class SuiteCode
{
    /** @var array<string, array{list<string>, list<string>}> what each file read so far declares and mentions */
    private array $names = [];

    private function __construct(private readonly bool $keepsNames)
    {
    }

    /** Code that keeps no file's names, for a run that reads no name graph. */
    public static function unkept(): self
    {
        return new self(keepsNames: false);
    }

    /** Code that keeps each file's names until the name graph takes them. */
    public static function namesKept(): self
    {
        return new self(keepsNames: true);
    }

    /** A file's code, read from what it holds. */
    public function of(Path $path, Contents $contents): PhpFile
    {
        $read = PhpFile::read($contents);

        if ($this->keepsNames) {
            $this->names[$path->value()] = $this->namesOf($read);
        }

        return $read;
    }

    /**
     * What each of these files declares and mentions, by its path, in their
     * order: those kept taken, every other file read.
     *
     * @param ByPath<Contents> $files
     *
     * @return array{array<string, list<string>>, array<string, list<string>>} declared, then mentioned
     */
    public function namesIn(ByPath $files): array
    {
        $declares = [];
        $mentions = [];

        foreach ($files as $path => $contents) {
            $key = $path->value();
            [$declares[$key], $mentions[$key]] = array_key_exists($key, $this->names)
                ? $this->names[$key]
                : $this->namesOf(PhpFile::read($contents));
        }

        $this->names = [];

        return [$declares, $mentions];
    }

    /** @return array{list<string>, list<string>} */
    private function namesOf(PhpFile $read): array
    {
        return [NamedFiles::declaredIn($read), NamedFiles::mentionedIn($read)];
    }
}
