<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function file_get_contents;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Definition\ContextsKeys;
use NightWorksIO\MutationGate\Core\Config\Definition\Field;
use NightWorksIO\MutationGate\Core\Config\Definition\Placement;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\Format\Json;

/**
 * The config's JSON Schema this package ships, read one key at a time, beside
 * each key's schema as its definition writes it, so a key's definition is
 * judged without writing the whole schema.
 */
final class ShippedSchema
{
    private static ?Json $shipped = null;

    private static ?Json $standard = null;

    /**
     * Each of these keys' schemas as the shipped schema holds them, at the top
     * of a config or in a context block; null for a key it does not hold.
     *
     * @param  list<string>               $keys
     * @return array<string, string|null>
     */
    public static function holds(array $keys, Placement $placement): array
    {
        $properties = $placement === Placement::TopLevel
            ? self::member(self::shipped(), 'properties')
            : self::member(self::member(self::member(self::shipped(), '$defs'), ContextsKeys::BLOCK), 'properties');
        $held = [];

        foreach ($keys as $key) {
            $schema = self::member($properties, $key);
            $held[$key] = $schema instanceof Json ? $schema->pretty() : null;
        }

        return $held;
    }

    /**
     * Each of these keys' schemas as the definition writes them: at the top of
     * a config with the value each takes when no layer sets it, and in a
     * context block with none.
     *
     * @param  list<Field<object|scalar>> $fields
     * @return array<string, string|null>
     */
    public static function written(array $fields, Placement $placement): array
    {
        $written = [];

        foreach ($fields as $field) {
            $default = $placement === Placement::TopLevel ? self::member(self::standard(), $field->key()) : Absent::setting();
            $written[$field->key()] = $field->schema($default)->pretty();
        }

        return $written;
    }

    private static function standard(): Json
    {
        return self::$standard ??= Layer::standard()->written(ProjectRoot::origin());
    }

    private static function shipped(): Json
    {
        if (!self::$shipped instanceof Json) {
            $parsed = Json::parse((string) file_get_contents(Tree::at('resources/mutation-gate.schema.json')));
            self::$shipped = $parsed instanceof Json ? $parsed : Json::object();
        }

        return self::$shipped;
    }

    private static function member(Json|Absent $object, string $key): Json|Absent
    {
        if ($object instanceof Json) {
            foreach ($object as $name => $value) {
                if ($name === $key) {
                    return $value;
                }
            }
        }

        return Absent::setting();
    }
}
