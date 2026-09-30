<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use function array_find;
use function array_map;
use function array_pop;

use BackedEnum;

use function implode;
use function sprintf;

/**
 * One of a closed set of words, read into the enum case it names.
 *
 * @template-covariant T of BackedEnum
 */
final readonly class Enumerated implements Node
{
    /** @param list<T> $cases */
    private function __construct(private array $cases)
    {
    }

    /**
     * @template U of BackedEnum
     *
     * @param  list<U>  $cases
     * @return self<U>
     */
    public static function of(array $cases): self
    {
        return new self($cases);
    }

    public function read(mixed $value, string $at): Reading
    {
        $case = array_find($this->cases, static fn(BackedEnum $case): bool => $case->value === $value);

        return $case instanceof BackedEnum
            ? Reading::of($case, $value)
            : Reading::mismatch($at, $this->expected(), $value);
    }

    public function expected(): string
    {
        $quoted = array_map(static fn(BackedEnum $case): string => sprintf('"%s"', $case->value), $this->cases);
        $last = array_pop($quoted);

        return sprintf('%s or %s', implode(', ', $quoted), $last);
    }

    public function schema(): array
    {
        return ['enum' => array_map(static fn(BackedEnum $case): int|string => $case->value, $this->cases)];
    }

    public function effects(): array
    {
        return [];
    }
}
