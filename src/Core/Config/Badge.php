<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Report\BadgeColors;

use function sprintf;

/** The badge's colours (ADR-0009): `badge.colors`, the lowest score of each shields.io colour, red below them all. */
final readonly class Badge implements Part
{
    private function __construct(private Table|Absent $colors)
    {
    }

    public static function of(Table|Absent $colors): self
    {
        return new self($colors);
    }

    public static function none(): self
    {
        return self::of(Absent::setting());
    }

    public static function standard(): self
    {
        return self::of(self::none()->colors());
    }

    /**
     * A later layer's colours replace an earlier one's: they are bands of one scale, so a colour laid among
     * another layer's would cut the scale into bands neither wrote.
     */
    public function over(Part $later): self
    {
        return $later instanceof self ? new self(Absent::laid($this->colors, $later->colors)) : $this;
    }

    /** `badge.colors`: the lowest score of each shields.io colour, red below them all. */
    public function colors(): Table
    {
        if ($this->colors instanceof Table) {
            return $this->colors;
        }

        $colors = Table::none();

        foreach (BadgeColors::DEFAULTS as $color => $lowest) {
            $colors = $colors->merged(Table::row($color, $lowest));
        }

        return $colors;
    }

    public function written(PathOrigin $origin): Json
    {
        return Json::object(Member::unlessEmpty(
            'badge',
            Json::object(
                Member::of('colors', $this->colors instanceof Table ? $this->colors->written() : $this->colors),
            ),
        ));
    }

    public function php(PathOrigin $origin): PhpCalls
    {
        $calls = [];

        foreach ($this->colors instanceof Table ? $this->colors : [] as $colour => $lowest) {
            $calls[] = sprintf('Badge::colour(%s, %s)', PhpCalls::literal($colour), PhpCalls::literal($lowest));
        }

        return PhpCalls::inWith(...$calls);
    }
}
