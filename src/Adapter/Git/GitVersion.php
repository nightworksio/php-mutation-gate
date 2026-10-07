<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Git;

use function preg_match;

/**
 * The version of git, as `git version` prints it: `git version 2.50.1`, with
 * whatever a vendor adds after it. A version it cannot read is older than
 * every version, so nothing that needs a newer git is tried with it.
 */
final readonly class GitVersion
{
    /** The major and minor version, after the words `git version`. */
    private const string PRINTED = '/^git version (\d+)\.(\d+)/';

    private function __construct(private int $major, private int $minor)
    {
    }

    /** The version git printed. */
    public static function printed(string $printed): self
    {
        return preg_match(self::PRINTED, $printed, $parts) === 1
            ? new self((int) $parts[1], (int) $parts[2])
            : new self(0, 0);
    }

    /** Whether this is that version, or a newer one. */
    public function isAtLeast(self $that): bool
    {
        return $this->major > $that->major || ($this->major === $that->major && $this->minor >= $that->minor);
    }
}
