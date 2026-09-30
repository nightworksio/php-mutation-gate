<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use function array_combine;
use function array_filter;
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

use function sprintf;

/**
 * An object of named settings. Every key it does not declare is refused,
 * with the declared key nearest to it, because a misspelt key that is
 * silently ignored is a setting that silently does nothing.
 *
 * @template-covariant T
 */
final readonly class Section implements Node
{
    /**
     * @param Closure(Fields, string): (T|Invalid) $build        the value its settings make, or their problems
     * @param array<string, Field>                 $fields       by key
     * @param list<list<string>>                   $alternatives sets of keys of which exactly one is written
     * @param list<string>                         $exclusive    keys of which at most one is written
     */
    private function __construct(
        private Closure $build,
        private array $fields,
        private array $alternatives,
        private array $exclusive,
    ) {
    }

    /**
     * @template U
     *
     * @param  Closure(Fields, string): (U|Invalid) $build the value its settings make, given them and its path
     * @return self<U>
     */
    public static function of(Closure $build, Field ...$fields): self
    {
        $keys = array_map(static fn(Field $field): string => $field->key(), $fields);

        return new self($build, array_combine($keys, $fields), [], []);
    }

    /** @return self<Fields> an object whose value is its settings */
    public static function fields(Field ...$fields): self
    {
        return self::of(static fn(Fields $read): Fields => $read, ...$fields);
    }

    /**
     * This object, where exactly one of these sets of keys is written.
     *
     * @param  list<list<string>> $sets
     * @return self<T>
     */
    public function oneOf(array $sets): self
    {
        return new self($this->build, $this->fields, $sets, $this->exclusive);
    }

    /**
     * This object, where at most one of these keys is written, as one replaces the other.
     *
     * @param  list<string> $keys
     * @return self<T>
     */
    public function atMostOne(array $keys): self
    {
        return new self($this->build, $this->fields, $this->alternatives, $keys);
    }

    public function read(mixed $value, string $at): Reading
    {
        if (! Json::isMap($value)) {
            return Reading::mismatch($at, $this->expected(), $value);
        }

        $readings = array_map(static fn(Field $field): Reading => $field->read($value, $at), $this->fields);
        $problems = [];

        foreach ($readings as $reading) {
            $problems = [...$problems, ...$reading->problems()];
        }

        $problems = [...$problems, ...$this->unknown($value, $at), ...$this->together($value, $at)];

        return $problems === [] ? $this->built($readings, $at) : Reading::refused($problems);
    }

    public function expected(): string
    {
        return 'an object';
    }

    public function schema(): array
    {
        $schema = ['type' => 'object'];

        if ($this->fields !== []) {
            $schema['properties'] = array_map(static fn(Field $field): array => $field->schema(), $this->fields);
        }

        $required = array_keys(array_filter($this->fields, static fn(Field $field): bool => $field->isRequired()));

        if ($required !== []) {
            $schema['required'] = $required;
        }

        $schema['additionalProperties'] = false;

        if ($this->exclusive !== []) {
            $schema['not'] = ['required' => $this->exclusive];
        }

        if ($this->alternatives !== []) {
            $schema['oneOf'] = array_map(static fn(array $set): array => ['required' => $set], $this->alternatives);
        }

        return $schema;
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

    /** @param array<string, Reading> $readings */
    private function built(array $readings, string $at): Reading
    {
        $values = array_map(static fn(Reading $reading): mixed => $reading->value(), $readings);
        $built = ($this->build)(new Fields($values), $at);

        if ($built instanceof Invalid) {
            return Reading::refused([...$built]);
        }

        $results = array_filter(
            array_map(static fn(Reading $reading): mixed => $reading->results(), $readings),
            static fn(mixed $results): bool => ! $results instanceof Absent,
        );

        return Reading::affecting(
            $built,
            array_filter(
                array_map(static fn(Reading $reading): mixed => $reading->shown(), $readings),
                static fn(mixed $shown): bool => ! $shown instanceof Absent,
            ),
            $results === [] ? Absent::setting() : $results,
        );
    }

    /**
     * The keys of which at most one may be written, where more are.
     *
     * @param  array<mixed> $object
     * @return list<Problem>
     */
    private function together(array $object, string $at): array
    {
        $written = array_values(array_filter(
            $this->exclusive,
            static fn(string $key): bool => array_key_exists($key, $object),
        ));

        return count($written) > 1
            ? [Problem::at($at, sprintf('expected either %s, but not both', implode(' or ', $written)))]
            : [];
    }

    /**
     * @param  array<mixed> $object
     * @return list<Problem>
     */
    private function unknown(array $object, string $at): array
    {
        $problems = [];

        foreach (array_keys($object) as $key) {
            $name = sprintf('%s', $key);

            if (! array_key_exists($name, $this->fields)) {
                $nearest = Nearest::to($name, array_keys($this->fields));
                $problems[] = Problem::at(
                    At::key($at, $name),
                    $nearest === '' ? 'unknown key' : sprintf('unknown key, did you mean %s?', $nearest),
                );
            }
        }

        return $problems;
    }
}
