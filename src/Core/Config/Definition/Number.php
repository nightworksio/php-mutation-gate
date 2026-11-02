<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Score\Floor;

use function sprintf;

/**
 * A number in a range, kept as it is written: a whole number stays whole.
 * Its least is allowed, or, for a number that must be above it, refused.
 *
 * @internal
 *
 * @implements Shape<int|float>
 */
final readonly class Number implements Shape
{
    private function __construct(
        private int|float $least,
        private int|float|Absent $most,
        private bool $leastAllowed,
    ) {
    }

    public static function between(int|float $least, int|float $most): self
    {
        return new self($least, $most, leastAllowed: true);
    }

    /** A number above one, which it may not be, and at most another. */
    public static function aboveAndAtMost(int|float $least, int|float $most): self
    {
        return new self($least, $most, leastAllowed: false);
    }

    /** A percentage: a number from 0 to 100. */
    public static function percent(): self
    {
        return new self(0, Floor::whole()->written(), leastAllowed: true);
    }

    public static function atLeast(int|float $least): self
    {
        return new self($least, Absent::setting(), leastAllowed: true);
    }

    public function read(Node $at): Reading
    {
        $number = match ($at->kind()) {
            Kind::Integer => $at->integer(),
            Kind::Number => $at->number(),
            Kind::Map, Kind::List, Kind::Empty, Kind::Text, Kind::Boolean, Kind::Null, Kind::Nothing
                => Absent::setting(),
        };

        return ! $number instanceof Absent && $this->above($number) && $this->fits($number)
            ? Reading::of($number)
            : Reading::refused($at->mismatch($this->expected()));
    }

    public function expected(): string
    {
        return match (true) {
            $this->most instanceof Absent => sprintf('a number of at least %s', $this->least),
            $this->leastAllowed => sprintf('a number from %s to %s', $this->least, $this->most),
            default => sprintf('a number above %s and at most %s', $this->least, $this->most),
        };
    }

    public function schema(): Json
    {
        $schema = Json::object(Member::of('type', 'number'))
            ->with(Member::of($this->leastAllowed ? 'minimum' : 'exclusiveMinimum', $this->least));

        return $this->most instanceof Absent ? $schema : $schema->with(Member::of('maximum', $this->most));
    }

    public function effects(): array
    {
        return [];
    }

    private function above(int|float $number): bool
    {
        return $this->leastAllowed ? $number >= $this->least : $number > $this->least;
    }

    private function fits(int|float $number): bool
    {
        return $this->most instanceof Absent || $number <= $this->most;
    }
}
