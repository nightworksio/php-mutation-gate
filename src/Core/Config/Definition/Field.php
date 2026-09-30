<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Node;

use function sprintf;

/**
 * One key of an object of settings: the shape of its value, what leaving it
 * out means, and what it can change.
 *
 * @template-covariant T of object|scalar
 */
final readonly class Field
{
    /** @param Shape<T> $shape */
    private function __construct(
        private string $key,
        private Shape $shape,
        private Effect|Absent $effect,
        private Presence $presence,
    ) {
    }

    /**
     * A setting a layer may leave out, for a later layer or its default to give.
     *
     * @template U of object|scalar
     *
     * @param  Shape<U> $shape
     * @return self<U>
     */
    public static function optional(string $key, Shape $shape, Effect $effect): self
    {
        return new self($key, $shape, $effect, Presence::Optional);
    }

    /**
     * A setting that must be written.
     *
     * @template U of object|scalar
     *
     * @param  Shape<U> $shape
     * @return self<U>
     */
    public static function required(string $key, Shape $shape, Effect $effect): self
    {
        return new self($key, $shape, $effect, Presence::Required);
    }

    /**
     * A list whose entries' own settings declare what each can change.
     *
     * @template U of object|scalar
     *
     * @param  Shape<U> $shape
     * @return self<U>
     */
    public static function entries(string $key, Shape $shape): self
    {
        return new self($key, $shape, Absent::setting(), Presence::Optional);
    }

    /**
     * An object of settings, each of which declares what it can change. Leaving it out leaves them all out.
     *
     * @template U of object|scalar
     *
     * @param  Section<U> $section
     * @return self<U>
     */
    public static function section(string $key, Section $section): self
    {
        return new self($key, $section, Absent::setting(), Presence::Section);
    }

    public function key(): string
    {
        return $this->key;
    }

    /** Whether a config must write it, as the JSON Schema says. */
    public function isRequired(): bool
    {
        return $this->presence === Presence::Required;
    }

    /**
     * This key of an object, read from the object.
     *
     * @return Reading<T>
     */
    public function read(Node $object): Reading
    {
        $at = $object->field($this->key);

        return match (true) {
            $at->kind() !== Kind::Nothing, $this->presence === Presence::Section => $this->shape->read($at),
            $this->presence === Presence::Required => Reading::refused($at->mismatch($this->shape->expected())),
            default => Reading::nothing(),
        };
    }

    /** Its JSON Schema, with the value it takes when every layer leaves it out, where it takes one. */
    public function schema(Json|Absent $default): Json
    {
        return match (true) {
            $this->shape instanceof Section => $this->shape->schemaUnder($default),
            $default instanceof Json => $this->shape->schema()->with('default', $default),
            default => $this->shape->schema(),
        };
    }

    /** @return array<string, Effect> this setting and every setting under it, by its path from the object */
    public function effects(): array
    {
        $effects = $this->effect instanceof Effect ? [$this->key => $this->effect] : [];

        foreach ($this->shape->effects() as $path => $effect) {
            $effects[sprintf('%s%s', $this->key, $path)] = $effect;
        }

        return $effects;
    }
}
