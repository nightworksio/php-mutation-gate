<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Config\Definition\Field;
use NightWorksIO\MutationGate\Core\Config\Definition\Number;
use NightWorksIO\MutationGate\Core\Config\Definition\NumberMap;
use NightWorksIO\MutationGate\Core\Config\Definition\Section;
use NightWorksIO\MutationGate\Core\Format\Json;

use function sprintf;

/** The badge's colours (ADR-0009): `badge.colors`, the lowest score of each shields.io colour, red below them all. */
final readonly class Badge implements Part
{
    /** The widest a percentage goes. */
    private const int WHOLE = 100;

    /** The lowest score of each colour. */
    private const array COLORS = ['brightgreen' => 90, 'green' => 80, 'yellow' => 70, 'orange' => 60];

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

    /** @return list<Field<Layer>> */
    public static function fields(): array
    {
        return [Field::section(
            'badge',
            Section::single(
                Field::optional('colors', NumberMap::of(Number::between(0, self::WHOLE)), Effect::JudgesOrReportsOnly),
                static fn(Table|Absent $colors): Layer => Layer::of(self::of($colors)),
            ),
        )];
    }

    /** A later layer's colours are laid over an earlier one's, by colour. */
    public function over(Part $later): self
    {
        return $later instanceof self
            ? new self(match (true) {
                $later->colors instanceof Absent => $this->colors,
                $this->colors instanceof Absent => $later->colors,
                default => $this->colors->merged($later->colors),
            })
            : $this;
    }

    /** `badge.colors`: the lowest score of each shields.io colour, red below them all. */
    public function colors(): Table
    {
        return $this->colors instanceof Table ? $this->colors : Table::of(self::COLORS);
    }

    public function written(Origin $origin): Json
    {
        return $this->colors instanceof Table
            ? Json::object()->with('badge', Json::object()->with('colors', $this->colors->written()))
            : Json::object();
    }

    public function php(Origin $origin): PhpCalls
    {
        $calls = [];

        foreach ($this->colors instanceof Table ? $this->colors : [] as $colour => $lowest) {
            $calls[] = sprintf('Badge::colour(%s, %s)', PhpCalls::literal($colour), PhpCalls::literal($lowest));
        }

        return PhpCalls::inWith(...$calls);
    }
}
