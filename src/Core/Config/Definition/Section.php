<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use function array_filter;
use function array_flip;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_values;

use Closure;

use function count;
use function implode;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;

use function sprintf;

/**
 * An object of named settings, read into the value its settings make. Every
 * key it does not declare is refused, with the declared key nearest to it,
 * because a misspelt key that is silently ignored is a setting that silently
 * does nothing.
 *
 * @template-covariant T of object|scalar
 *
 * @implements Shape<T>
 */
final readonly class Section implements Shape
{
    /** What a problem says an object is. */
    private const string EXPECTED = 'an object';

    /**
     * @param Closure(Node): (T|Invalid)      $build        the value its settings make, or their problems
     * @param list<Field<object|scalar>>      $fields
     * @param list<list<string>>              $alternatives sets of keys of which exactly one is written
     * @param list<string>                    $exclusive    keys of which at most one is written
     * @param Json|Absent                     $defaults     the value each setting takes when it is left out
     */
    private function __construct(
        private Closure $build,
        private array $fields,
        private array $alternatives,
        private array $exclusive,
        private Json|Absent $defaults,
    ) {
    }

    /**
     * An object whose settings, read from it by the builder, make one value.
     *
     * @template U of object|scalar
     *
     * @param  Closure(Node): (U|Invalid)       $build
     * @param  Field<object|scalar>       ...$fields
     * @return self<U>
     */
    public static function of(Closure $build, Field ...$fields): self
    {
        return new self($build, array_values($fields), [], [], Absent::setting());
    }

    /**
     * An object of one setting, whose value, or nothing, makes the object's.
     *
     * @template V of object|scalar
     * @template U of object
     *
     * @param  Field<V>                     $field
     * @param  Closure(V|Absent): (U|Invalid) $build
     * @return self<U>
     */
    public static function single(Field $field, Closure $build): self
    {
        return new self(
            static function (Node $at) use ($field, $build): object {
                $reading = $field->read($at);

                return Reading::built(static fn(): object => $build($reading->value()), $reading);
            },
            [$field],
            [],
            [],
            Absent::setting(),
        );
    }

    /**
     * An object whose settings are checked and kept as they are written, laid over the values they take when
     * they are left out: the options of a built-in adapter.
     *
     * @param  Field<object|scalar> ...$fields
     * @return self<Json>
     */
    public static function options(Json $defaults, Field ...$fields): self
    {
        $checked = array_values($fields);

        return new self(
            static function (Node $with) use ($checked, $defaults): Json|Invalid {
                $problems = Reading::problemsIn(...array_map(
                    static fn(Field $field): Reading => $field->read($with),
                    $checked,
                ));

                return $problems instanceof Invalid
                    ? $problems
                    : $defaults->merged(
                        $with->kind() === Kind::Nothing || $with->kind() === Kind::Empty
                            ? Json::object()
                            : $with->value(),
                    );
            },
            $checked,
            [],
            [],
            $defaults,
        );
    }

    /**
     * This object, where exactly one of these sets of keys is written.
     *
     * @param  list<list<string>> $sets
     * @return self<T>
     */
    public function oneOf(array $sets): self
    {
        return new self($this->build, $this->fields, $sets, $this->exclusive, $this->defaults);
    }

    /**
     * This object, where at most one of these keys is written, as one replaces the other.
     *
     * @param  list<string> $keys
     * @return self<T>
     */
    public function atMostOne(array $keys): self
    {
        return new self($this->build, $this->fields, $this->alternatives, $keys, $this->defaults);
    }

    /**
     * This object, whose settings take these values where a config leaves them out, as its schema says.
     *
     * @return self<T>
     */
    public function defaulting(Json $defaults): self
    {
        return new self($this->build, $this->fields, $this->alternatives, $this->exclusive, $defaults);
    }

    public function read(Node $at): Reading
    {
        if (! self::isObject($at)) {
            return Reading::refused($at->mismatch(self::EXPECTED));
        }

        $built = ($this->build)($at);
        $problems = [
            ...$built instanceof Invalid ? [...$built] : [],
            ...$this->unknown($at),
            ...$this->together($at),
        ];

        return match (true) {
            $problems !== [] => Reading::invalid(Invalid::because(...$problems)),
            $built instanceof Invalid => Reading::invalid($built),
            default => Reading::of($built),
        };
    }

    public function expected(): string
    {
        return self::EXPECTED;
    }

    public function schema(): Json
    {
        return $this->schemaUnder($this->defaults);
    }

    /** Its JSON Schema, with the value each of its settings takes when every layer leaves it out. */
    public function schemaUnder(Json|Absent $defaults): Json
    {
        $schema = Json::object(Member::of('type', 'object'));
        $properties = Json::object();

        foreach ($this->fields as $field) {
            $default = $defaults instanceof Json ? self::member($defaults, $field->key()) : $defaults;
            $properties = $properties->with(Member::of($field->key(), $field->schema($default)));
        }

        $required = array_values(array_map(
            static fn(Field $field): string => $field->key(),
            array_filter($this->fields, static fn(Field $field): bool => $field->isRequired()),
        ));
        $schema = $this->fields === [] ? $schema : $schema->with(Member::of('properties', $properties));
        $schema = $required === [] ? $schema : $schema->with(Member::of('required', Json::items(...$required)));
        $schema = $schema->with(Member::of('additionalProperties', value: false));
        $schema = $this->exclusive === []
            ? $schema
            : $schema->with(Member::of('not', Json::object(Member::of('required', Json::items(...$this->exclusive)))));

        return $this->alternatives === [] ? $schema : $schema->with(
            Member::of(
                'oneOf',
                Json::items(...array_map(
                    static fn(array $set): Json => Json::object(Member::of('required', Json::items(...$set))),
                    $this->alternatives,
                )),
            ),
        );
    }

    public function effects(): array
    {
        $effects = [];

        foreach ($this->settings() as $path => $effect) {
            $effects[sprintf('.%s', $path)] = $effect;
        }

        return $effects;
    }

    /** @return array<string, Effect> every setting in this object, by its path from it */
    public function settings(): array
    {
        $settings = [];

        foreach ($this->fields as $field) {
            $settings = [...$settings, ...$field->effects()];
        }

        return $settings;
    }

    /** What an object holds under a key, or nothing. */
    private static function member(Json $object, string $key): Json|Absent
    {
        foreach ($object as $name => $value) {
            if ($name === $key) {
                return $value;
            }
        }

        return Absent::setting();
    }

    /** Whether a place holds an object, or nothing, which reads as an object with every setting left out. */
    private static function isObject(Node $at): bool
    {
        return match ($at->kind()) {
            Kind::Map, Kind::Empty, Kind::Nothing => true,
            Kind::List, Kind::Text, Kind::Integer, Kind::Number, Kind::Boolean, Kind::Null => false,
        };
    }

    /**
     * The keys of which at most one may be written, where more are.
     *
     * @return list<Problem>
     */
    private function together(Node $at): array
    {
        $written = array_values(array_filter(
            $this->exclusive,
            static fn(string $key): bool => $at->field($key)->kind() !== Kind::Nothing,
        ));

        return count($written) > 1
            ? [Problem::at($at->at(), sprintf('expected either %s, but not both', implode(' or ', $written)))]
            : [];
    }

    /** @return list<Problem> */
    private function unknown(Node $at): array
    {
        $declared = array_map(static fn(Field $field): string => $field->key(), $this->fields);
        $known = array_flip($declared);
        $problems = [];

        foreach ($at->kind() === Kind::Map ? array_keys($at->entries()) : [] as $key) {
            if (! array_key_exists($key, $known)) {
                $nearest = Nearest::to($key, $declared);
                $problems[] = Problem::at(
                    $at->field($key)->at(),
                    $nearest === '' ? 'unknown key' : sprintf('unknown key, did you mean %s?', $nearest),
                );
            }
        }

        return $problems;
    }
}
