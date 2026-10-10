<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Turbo;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * The SHA-256 of each binary of the helper a gate runs, by platform: the gate
 * refuses a binary whose digest is not its pin, so a swapped package cannot
 * bring its own (ADR-0029). The release workflow writes this gate's table;
 * a gate built before any release of the helper pins none.
 */
final readonly class Pinned
{
    /** @var array<string, string> this gate's pins, by platform */
    private const array RELEASE = [];

    /** @param array<string, string> $digests by platform */
    private function __construct(private array $digests)
    {
    }

    /** This gate's own pins. */
    public static function release(): self
    {
        return new self(self::RELEASE);
    }

    /** @param array<string, string> $digests each platform's pinned SHA-256, by its name */
    public static function of(array $digests): self
    {
        return new self($digests);
    }

    /** The pinned digest of the binary for this platform; nothing where none is pinned. */
    public function digestOf(Platform $platform): Digest|NotGiven
    {
        return array_key_exists($platform->value, $this->digests)
            ? Digest::of($this->digests[$platform->value])
            : NotGiven::value();
    }
}
