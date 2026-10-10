<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof\Key;

use function array_key_exists;
use function count;
use function hash_update;

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Php\NamedFiles;
use NightWorksIO\MutationGate\Core\Php\ReachedFiles;

use function sprintf;

/**
 * The key of a test file's coverage entries (ADR-0023, decision 1): one
 * SHA-256 over what every entry reads, then each file the entries can depend
 * on, by its digest: the test file, the files its tests executed, what runs
 * before every test, and every file those name, transitively, through the
 * project's name graph. A file not there is read as missing. An entry whose
 * code, support and executed files are unchanged takes the same path, so it
 * covers the same lines; a test that could reach new code must execute or
 * name a changed file to get there, so its key moves.
 */
final readonly class EntryKeying
{
    /** The format of an entry's key, which changes whenever what a key reads changes. */
    public const string FORMAT = 'mutation-gate coverage entry 2';

    /**
     * @param array<string, string> $framed each file of the graph, framed with its digest as a key reads it, by its
     *                                      path
     */
    private function __construct(
        private Digest $base,
        private ReachedFiles $reached,
        private Fingerprints $files,
        private Paths $always,
        private array $framed,
    ) {
    }

    /**
     * Keys over this base, following names through this graph, each file by
     * its digest among these, with these files read by every entry. What each
     * file reaches, and each file framed with its digest, is worked out once.
     */
    public static function of(Digest $base, NamedFiles $names, Fingerprints $files, Paths $always): self
    {
        $reached = $names->reaches();
        $framed = [];

        foreach ($reached->files() as $path) {
            $framed[$path] = self::framedOf($files, $path);
        }

        return new self($base, $reached, $files, $always, $framed);
    }

    /** The key of a test file's entries, whose tests executed these files. */
    public function keyOf(Path $testFile, Paths $executed): Digest
    {
        $from = [$testFile->value()];

        foreach ([...$executed, ...$this->always] as $file) {
            $from[] = $file->value();
        }

        $read = $this->reached->from(...$from);
        $context = Digest::hashing();
        hash_update($context, ContentKeys::framed(self::FORMAT, $this->base->value(), sprintf('%d', count($read))));

        foreach ($read as $path) {
            hash_update(
                $context,
                array_key_exists($path, $this->framed) ? $this->framed[$path] : self::framedOf($this->files, $path),
            );
        }

        return Digest::finished($context);
    }

    /** A file framed with its digest, or as missing, as a key reads it. */
    private static function framedOf(Fingerprints $files, string $path): string
    {
        $digest = $files->digestOf(Path::of($path));

        return ContentKeys::framed($path, $digest instanceof Missing ? Missing::DIGESTED : $digest->value());
    }
}
