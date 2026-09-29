<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use function array_values;

use NightWorksIO\MutationGate\Core\Config\Definition\Json;

/**
 * One option written beside an adapter, under `with`: `Option::of('channel', '#ci')`.
 * An adapter a config names by its class checks its own options.
 */
final readonly class Option
{
    private function __construct(private string $key, private string $json)
    {
    }

    public static function of(string $key, string|int|float|bool $value): self
    {
        return new self($key, Json::encode($value));
    }

    public static function list(string $key, string|int|float|bool ...$values): self
    {
        return new self($key, Json::encode(array_values($values)));
    }

    /** An option whose value is an object of options. */
    public static function nested(string $key, self ...$options): self
    {
        return new self($key, self::object(...$options));
    }

    /** Options as the JSON object they make, `{}` when there are none. */
    public static function object(self ...$options): string
    {
        $object = [];

        foreach ($options as $option) {
            $object[$option->key] = Json::decode($option->json);
        }

        return Json::encode(Json::object($object));
    }

    /** An adapter as a setting chooses it: its name alone, or `{"use": …, "with": …}` when it has options. */
    public static function choice(string $use, self ...$options): string
    {
        return $options === []
            ? Json::encode($use)
            : Json::encode(['use' => $use, 'with' => Json::decode(self::object(...$options))]);
    }

    /** This option as the JSON object it makes on its own. */
    public function written(): string
    {
        return self::object($this);
    }
}
