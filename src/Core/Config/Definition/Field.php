<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Problem;

use function sprintf;

/**
 * One key of an object in a config: what its value must be, what it means
 * when it is not written, and what the setting can change.
 */
final readonly class Field
{
    private function __construct(
        private string $key,
        private Node $node,
        private Effect|Absent $effect,
        private Presence $presence,
        private mixed $default,
    ) {
    }

    /** A setting that takes this default, written as a config would write it, when it is not written. */
    public static function setting(string $key, Node $node, Effect $effect, mixed $default): self
    {
        return new self($key, $node, $effect, Presence::Defaulted, $default);
    }

    /** A setting whose absence means something of its own. */
    public static function optional(string $key, Node $node, Effect $effect): self
    {
        return new self($key, $node, $effect, Presence::Optional, Absent::setting());
    }

    /** A setting that must be written. */
    public static function required(string $key, Node $node, Effect $effect): self
    {
        return new self($key, $node, $effect, Presence::Required, Absent::setting());
    }

    /** A list whose entries' own settings declare what each can change, and whose absence means something. */
    public static function entries(string $key, Node $node): self
    {
        return new self($key, $node, Absent::setting(), Presence::Optional, Absent::setting());
    }

    /** An object of settings, each of which declares what it can change. */
    public static function section(string $key, Node $node): self
    {
        return new self($key, $node, Absent::setting(), Presence::Section, []);
    }

    public function key(): string
    {
        return $this->key;
    }

    public function isRequired(): bool
    {
        return $this->presence === Presence::Required;
    }

    /**
     * This key of an object, read at the object's path.
     *
     * @param array<mixed> $object
     */
    public function read(array $object, string $at): Reading
    {
        $path = At::key($at, $this->key);

        if (array_key_exists($this->key, $object)) {
            return $this->counted($this->node->read($object[$this->key], $path));
        }

        return match ($this->presence) {
            Presence::Optional => Reading::nothing(),
            Presence::Required => Reading::refused([
                Problem::at($path, sprintf('expected %s, got nothing', $this->node->expected())),
            ]),
            Presence::Defaulted, Presence::Section => $this->counted($this->node->read($this->default, $path)),
        };
    }

    /** @return array<string, mixed> */
    public function schema(): array
    {
        $schema = $this->node->schema();

        return $this->presence === Presence::Defaulted
            ? [...$schema, 'default' => $this->node->read($this->default, $this->key)->shown()]
            : $schema;
    }

    /** @return array<string, Effect> this setting and every setting under it, by its path from the object */
    public function effects(): array
    {
        $effects = $this->effect instanceof Effect ? [$this->key => $this->effect] : [];

        foreach ($this->node->effects() as $path => $effect) {
            $effects[sprintf('%s%s', $this->key, $path)] = $effect;
        }

        return $effects;
    }

    private function counted(Reading $reading): Reading
    {
        return $this->effect instanceof Effect ? $reading->under($this->effect) : $reading;
    }
}
