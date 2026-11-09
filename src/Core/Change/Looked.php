<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Change;

use NightWorksIO\MutationGate\Core\File\Fingerprints;

/**
 * One look at the working tree (ADR-0010, ADR-0120): every file with its
 * digest, the commit the look was taken at, and the paths version control
 * said differ from that commit then. A later look at the same commit hashes
 * again only what differs from it now or differed then; a look with no
 * commit is one the next look takes whole.
 *
 * @internal
 */
final readonly class Looked
{
    /** The commit of a look version control could not name, which the next look takes whole. */
    private const string NO_COMMIT = '';

    /** @param list<string> $differing */
    private function __construct(private Fingerprints $files, private string $commit, private array $differing)
    {
    }

    /** No look yet: the next one is taken whole. */
    public static function none(): self
    {
        return new self(Fingerprints::none(), self::NO_COMMIT, []);
    }

    /** A look that hashed every file, which the next look takes whole as well. */
    public static function whole(Fingerprints $files): self
    {
        return new self($files, self::NO_COMMIT, []);
    }

    /**
     * A look at this commit, and the paths that differed from it then.
     *
     * @param list<string> $differing
     */
    public static function at(Fingerprints $files, string $commit, array $differing): self
    {
        return new self($files, $commit, $differing);
    }

    public function files(): Fingerprints
    {
        return $this->files;
    }

    /** Whether a look at this commit may hash only what differs: this one was taken at it. */
    public function isAt(string $commit): bool
    {
        return $this->commit !== self::NO_COMMIT && $this->commit === $commit;
    }

    /** @return list<string> */
    public function differing(): array
    {
        return $this->differing;
    }
}
