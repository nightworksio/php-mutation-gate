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
 *
 * @implements Shape<int|float>
 */
final readonly class Number implements Shape
{
    private function __construct(private int|float $least, private int|float|Absent $most)
    {
    }

    public static function between(int|float $least, int|float $most): self
    {
        return new self($least, $most);
    }

    /** A percentage: a number from 0 to 100. */
    public static function percent(): self
    {
        return new self(0, Floor::whole()->written());
    }

    public static function atLeast(int|float $least): self
    {
        return new self($least, Absent::setting());
    }

    public function read(Node $at): Reading
    {
        $number = match ($at->kind()) {
            Kind::Integer => $at->integer(),
            Kind::Number => $at->number(),
            Kind::Map, Kind::List, Kind::Empty, Kind::Text, Kind::Boolean, Kind::Null, Kind::Nothing
                => Absent::setting(),
        };

        return ! $number instanceof Absent && $number >= $this->least && $this->fits($number)
            ? Reading::of($number)
            : Reading::refused($at->mismatch($this->expected()));
    }

    public function expected(): string
    {
        return $this->most instanceof Absent
            ? sprintf('a number of at least %s', $this->least)
            : sprintf('a number from %s to %s', $this->least, $this->most);
    }

    public function schema(): Json
    {
        $schema = Json::object(Member::of('type', 'number'))->with(Member::of('minimum', $this->least));

        return $this->most instanceof Absent ? $schema : $schema->with(Member::of('maximum', $this->most));
    }

    public function effects(): array
    {
        return [];
    }

    private function fits(int|float $number): bool
    {
        return $this->most instanceof Absent || $number <= $this->most;
    }
}
