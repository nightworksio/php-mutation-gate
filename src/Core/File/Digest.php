<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\File;

use function hash;

/** What a content hashes to: a git blob id, or a SHA-256 the gate took itself. */
final readonly class Digest
{
    private const string ALGORITHM = 'sha256';

    private function __construct(private string $value)
    {
    }

    /** A digest something else took, as it spells it. */
    public static function of(string $value): self
    {
        return new self($value);
    }

    /** The SHA-256 of a content, in lowercase hex. */
    public static function sha256Of(string $content): self
    {
        return new self(hash(self::ALGORITHM, $content));
    }

    public function value(): string
    {
        return $this->value;
    }
}
