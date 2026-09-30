<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Config\Definition\Field;
use NightWorksIO\MutationGate\Core\Config\Definition\Flag;
use NightWorksIO\MutationGate\Core\Config\Definition\Reading;
use NightWorksIO\MutationGate\Core\Config\Definition\Section;
use NightWorksIO\MutationGate\Core\Config\Definition\Text;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Test\Group;

use function sprintf;

/** The optional Pest patches, and the tests that show they work (ADR-0004): `pest`. */
final readonly class Pest implements Part
{
    private const string CANARY = 'mutation-canary';

    private function __construct(private bool|Absent $patch, private Group|Absent $canary)
    {
    }

    public static function of(bool|Absent $patch = new Absent(), Group|Absent $canary = new Absent()): self
    {
        return new self($patch, $canary);
    }

    public static function none(): self
    {
        return self::of();
    }

    public static function standard(): self
    {
        $none = self::none();

        return self::of($none->patch(), $none->canary());
    }

    /** @return list<Field<Layer>> */
    public static function fields(): array
    {
        $results = Effect::AffectsResults;
        $patch = Field::optional('patch', Flag::boolean(), $results);
        $canary = Field::optional('canary', Text::of('a group name'), $results);

        return [Field::section(
            'pest',
            Section::of(
                static function (Node $pest) use ($patch, $canary): Layer|Invalid {
                    $patched = $patch->read($pest);
                    $group = $canary->read($pest);
                    $named = $group->value();

                    return Reading::built(
                        static fn(): Layer => Layer::of(self::of(
                            $patched->value(),
                            $named instanceof Absent ? $named : Group::named($named),
                        )),
                        $patched,
                        $group,
                    );
                },
                $patch,
                $canary,
            ),
        )];
    }

    public function over(Part $later): self
    {
        return $later instanceof self
            ? new self(Absent::laid($this->patch, $later->patch), Absent::laid($this->canary, $later->canary))
            : $this;
    }

    /** Whether the optional Pest patches are applied. */
    public function patch(): bool
    {
        return ! $this->patch instanceof Absent && $this->patch;
    }

    /** The group of tests that shows the patches work. */
    public function canary(): Group
    {
        return $this->canary instanceof Group ? $this->canary : Group::named(self::CANARY);
    }

    public function written(Origin $origin): Json
    {
        $pest = $this->patch instanceof Absent ? Json::object() : Json::object()->with('patch', $this->patch);
        $pest = $this->canary instanceof Group ? $pest->with('canary', $this->canary->name()) : $pest;

        return $pest->isEmpty() ? $pest : Json::object()->with('pest', $pest);
    }

    public function php(Origin $origin): PhpCalls
    {
        return PhpCalls::inWith(...[
            ...$this->patch instanceof Absent ? [] : [$this->patch ? 'Pest::patched()' : 'Pest::unpatched()'],
            ...$this->canary instanceof Group
                ? [sprintf('Pest::canary(%s)', PhpCalls::literal($this->canary->name()))]
                : [],
        ]);
    }
}
