<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function intval;
use function is_int;

use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Runner\PerTestLimit;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * `timeouts.perTest` and `timeouts.testFloor` as a layer sets them
 * (ADR-0090): how many times its own time each test of a run may run, or
 * `false` for no such limit, and the least a test may run for.
 *
 * @internal
 */
final readonly class PerTestSetting
{
    private function __construct(private int|false|Absent $multiple, private Seconds|Absent $floor)
    {
    }

    public static function of(int|false|Absent $multiple = new Absent(), Seconds|Absent $floor = new Absent()): self
    {
        return new self($multiple, $floor);
    }

    /** This, under a later layer: what the later sets, and this where it sets nothing. */
    public function over(self $later): self
    {
        return new self(Absent::laid($this->multiple, $later->multiple), Absent::laid($this->floor, $later->floor));
    }

    /** The limit each test of a run is held to. */
    public function limit(): PerTestLimit
    {
        $floor = $this->floor instanceof Seconds ? $this->floor : PerTestLimit::standard()->floor();

        return match (true) {
            $this->multiple === false => PerTestLimit::off(),
            is_int($this->multiple) => PerTestLimit::of($this->multiple, $floor),
            default => PerTestLimit::of(PerTestLimit::MULTIPLE, $floor),
        };
    }

    /** @return list<Member> the members of `timeouts` it writes */
    public function written(): array
    {
        return [
            Member::of('perTest', $this->multiple),
            Member::of('testFloor', WholeSeconds::written($this->floor)),
        ];
    }

    /** @return list<string> the builder's calls that set it */
    public function calls(): array
    {
        return [
            ...match (true) {
                $this->multiple === false => ['Timeouts::noTestLimit()'],
                is_int($this->multiple) => [sprintf('Timeouts::perTest(%d)', $this->multiple)],
                default => [],
            },
            ...$this->floor instanceof Seconds
                ? [sprintf('Timeouts::testFloor(%d)', intval($this->floor->seconds()))]
                : [],
        ];
    }
}
