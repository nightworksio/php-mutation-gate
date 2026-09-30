<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use function array_flip;
use function array_key_exists;
use function array_keys;

use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\Effect;

use function sprintf;

/**
 * The adapters this package ships for one setting, each with the options it
 * takes. A built-in adapter's options are checked here; a name another
 * extension registers, or a class, checks its own when it is built.
 */
final readonly class Builtins
{
    /** @param array<string, Section<Fields>> $options the options of each built-in adapter, by its name */
    private function __construct(private array $options)
    {
    }

    /** @param array<string, Section<Fields>> $options */
    public static function of(array $options): self
    {
        return new self($options);
    }

    /**
     * The adapter a config chooses, with its options at `<at>.with`, and how it is shown: by its name alone
     * when it has no options to show.
     */
    public function choose(string $use, mixed $with, string $at): Reading
    {
        if (! array_key_exists($use, $this->options)) {
            return Reading::of(
                Choice::of($use, Json::encode(Json::object($with))),
                $with === [] ? $use : ['use' => $use, 'with' => $with],
            );
        }

        $options = $this->options[$use]->read($with, sprintf('%s.with', $at));
        $shown = $options->shown();

        return $options->problems() === []
            ? Reading::of(
                Choice::of($use, Json::encode(Json::object($shown))),
                $shown === [] ? $use : ['use' => $use, 'with' => $shown],
            )
            : $options;
    }

    public function has(string $use): bool
    {
        return array_key_exists($use, $this->options);
    }

    /**
     * The JSON Schema of each way to choose one: every built-in adapter with its own options, and any other
     * name or class with any options.
     *
     * @param  array<string, array<string, mixed>> $also         the other keys the object holds
     * @param  list<string>                        $alsoRequired those a built-in adapter needs
     * @param  list<string>                        $without      the built-in adapters that take none of them
     * @return list<array<string, mixed>>
     */
    public function schemas(array $also, array $alsoRequired, array $without): array
    {
        $schemas = [];
        $bare = array_flip($without);

        foreach ($this->options as $name => $options) {
            $own = array_key_exists($name, $bare);
            $schemas[] = [
                'type' => 'object',
                'properties' => ['use' => ['const' => $name], ...$own ? [] : $also, 'with' => $options->schema()],
                'required' => ['use', ...$own ? [] : $alsoRequired],
                'additionalProperties' => false,
            ];
        }

        $schemas[] = [
            'type' => 'object',
            'properties' => [
                'use' => ['type' => 'string', 'minLength' => 1, 'not' => ['enum' => array_keys($this->options)]],
                ...$also,
                'with' => ['type' => 'object'],
            ],
            'required' => ['use'],
            'additionalProperties' => false,
        ];

        return $schemas;
    }

    /** @return array<string, Effect> the options of every built-in adapter, by their path from the setting */
    public function effects(): array
    {
        $effects = [];

        foreach ($this->options as $options) {
            foreach ($options->effects() as $path => $effect) {
                $effects[sprintf('.with%s', $path)] = $effect;
            }
        }

        return $effects;
    }
}
