<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof\Key;

use function count;
use function hash_update;

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Php\NamedFiles;

use function sort;
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
    public const string FORMAT = 'mutation-gate coverage entry 1';

    private function __construct(
        private Digest $base,
        private NamedFiles $names,
        private Fingerprints $files,
        private Paths $always,
    ) {
    }

    /**
     * Keys over this base, following names through this graph, each file by
     * its digest among these, with these files read by every entry.
     */
    public static function of(Digest $base, NamedFiles $names, Fingerprints $files, Paths $always): self
    {
        return new self($base, $names, $files, $always);
    }

    /** The key of a test file's entries, whose tests executed these files. */
    public function keyOf(Path $testFile, Paths $executed): Digest
    {
        $from = [$testFile->value()];

        foreach ([...$executed, ...$this->always] as $file) {
            $from[] = $file->value();
        }

        $read = $this->names->reachedFrom(...$from);
        sort($read);
        $context = Digest::hashing();
        hash_update($context, ContentKeys::framed(self::FORMAT, $this->base->value(), sprintf('%d', count($read))));

        foreach ($read as $path) {
            $digest = $this->files->digestOf(Path::of($path));
            $written = $digest instanceof Missing ? Missing::DIGESTED : $digest->value();
            hash_update($context, ContentKeys::framed($path, $written));
        }

        return Digest::finished($context);
    }
}
