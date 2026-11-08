<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function getenv;
use function is_numeric;
use function max;

use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;

use function sprintf;

/**
 * What `splitStream` asks of a patched Pest run (ADR-0117), as the gate
 * tells Pest's own process: whether it splits its streams, the most children
 * a stream holds at once, whether a stream checks a pure expression's mutant
 * changes its value before it forks, and whether it forks first at each
 * mutant's likely killer.
 *
 * @internal
 */
final readonly class StreamSettings
{
    private const string ON = '1';

    private const string OFF = '0';

    private function __construct(
        private bool $enabled,
        private int $children,
        private bool $changeCheck,
        private bool $killersFirst,
    ) {
    }

    public static function off(): self
    {
        return new self(false, 1, true, true);
    }

    public static function of(bool $enabled, int $children, bool $changeCheck, bool $killersFirst): self
    {
        return new self($enabled, max(1, $children), $changeCheck, $killersFirst);
    }

    /** What the gate told this process. */
    public static function fromEnvironment(): self
    {
        $children = getenv(GateVariable::SplitChildren->value);

        return new self(
            getenv(GateVariable::Split->value) === self::ON,
            is_numeric($children) ? max(1, (int) $children) : 1,
            getenv(GateVariable::SplitChangeCheck->value) !== self::OFF,
            getenv(GateVariable::SplitKillersFirst->value) !== self::OFF,
        );
    }

    public function isOn(): bool
    {
        return $this->enabled;
    }

    public function children(): int
    {
        return $this->children;
    }

    public function checksChanges(): bool
    {
        return $this->changeCheck;
    }

    public function killersFirst(): bool
    {
        return $this->killersFirst;
    }

    /**
     * What a patched run is started with to split its streams so; nothing
     * where it does not.
     *
     * @return array<string, string>
     */
    public function variables(): array
    {
        return $this->enabled
            ? [
                GateVariable::Split->value => self::ON,
                GateVariable::SplitChildren->value => sprintf('%d', $this->children),
                GateVariable::SplitChangeCheck->value => $this->changeCheck ? self::ON : self::OFF,
                GateVariable::SplitKillersFirst->value => $this->killersFirst ? self::ON : self::OFF,
            ]
            : [];
    }
}
