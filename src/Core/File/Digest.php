<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\File;

use function hash;
use function hash_final;
use function hash_init;

use HashContext;

use function preg_match;

/** What a content hashes to: a git blob id, or a SHA-256 the gate took itself. */
final readonly class Digest
{
    private const string ALGORITHM = 'sha256';

    /** How the gate spells a SHA-256: in lowercase hex. */
    private const string SHA256 = '/^[0-9a-f]{64}$/D';

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

    /** A SHA-256 to feed in parts with `hash_update()`, and finish with `finished()`. */
    public static function hashing(): HashContext
    {
        return hash_init(self::ALGORITHM);
    }

    /** The SHA-256 a context fed in parts has taken, in lowercase hex. */
    public static function finished(HashContext $context): self
    {
        return new self(hash_final($context));
    }

    /** Whether a text spells a SHA-256 as the gate writes one. */
    public static function isSha256(string $text): bool
    {
        return preg_match(self::SHA256, $text) === 1;
    }

    public function value(): string
    {
        return $this->value;
    }
}
