<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use function is_string;
use function str_starts_with;

/** An `https://` URL, such as the public base a ledger is read from (ADR-0013). */
final readonly class Url implements Node
{
    private const string SCHEME = 'https://';

    public static function https(): self
    {
        return new self();
    }

    public function read(mixed $value, string $at): Reading
    {
        return is_string($value) && str_starts_with($value, self::SCHEME) && $value !== self::SCHEME
            ? Reading::of($value, $value)
            : Reading::mismatch($at, $this->expected(), $value);
    }

    public function expected(): string
    {
        return 'an https:// URL';
    }

    public function schema(): array
    {
        return ['type' => 'string', 'pattern' => '^https://.'];
    }

    public function effects(): array
    {
        return [];
    }
}
