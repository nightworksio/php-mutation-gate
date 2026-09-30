<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Test;

use function mb_strlen;
use function mb_substr;
use function sprintf;
use function str_starts_with;

/**
 * A group of tests, as the runner lists and selects it. A holding group,
 * `holds:<path>`, gathers the tests that hold a path, so a run can select
 * every one of them.
 */
final readonly class Group
{
    /** How the name of a holding group begins. */
    public const string HOLDING = 'holds:';

    private function __construct(private string $name)
    {
    }

    public static function named(string $name): self
    {
        return new self($name);
    }

    /** The group of the tests that hold a path. */
    public static function holding(string $path): self
    {
        return new self(sprintf('%s%s', self::HOLDING, $path));
    }

    public function name(): string
    {
        return $this->name;
    }

    /** Whether this is the group of the tests that hold a path. */
    public function isHolding(): bool
    {
        return str_starts_with($this->name, self::HOLDING);
    }

    /** The path a holding group's tests hold, as its name spells it. */
    public function held(): string
    {
        return mb_substr($this->name, mb_strlen(self::HOLDING));
    }
}
