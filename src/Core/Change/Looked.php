<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Change;

use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * One look at the working tree (ADR-0010, ADR-0120): every file with its
 * digest, the commit the look was taken at, and the paths version control
 * said differ from that commit then. A later look at the same commit hashes
 * again only what differs from it now or differed then; a look with no
 * commit is one the next look takes whole.
 */
final readonly class Looked
{
    private function __construct(
        private Fingerprints $files,
        private Revision|NotGiven $commit,
        private Paths $differing,
    ) {
    }

    /** No look yet: the next one is taken whole. */
    public static function none(): self
    {
        return new self(Fingerprints::none(), NotGiven::value(), Paths::none());
    }

    /** A look that hashed every file, which the next look takes whole as well. */
    public static function whole(Fingerprints $files): self
    {
        return new self($files, NotGiven::value(), Paths::none());
    }

    /** A look at this commit, and the paths that differed from it then. */
    public static function at(Fingerprints $files, Revision $commit, Paths $differing): self
    {
        return new self($files, $commit, $differing);
    }

    public function files(): Fingerprints
    {
        return $this->files;
    }

    /** Whether a look at this commit may hash only what differs: this one was taken at it. */
    public function isAt(Revision $commit): bool
    {
        return $this->commit instanceof Revision && $this->commit->name() === $commit->name();
    }

    public function differing(): Paths
    {
        return $this->differing;
    }
}
