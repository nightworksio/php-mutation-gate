<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Config\Definition\Reading;
use NightWorksIO\MutationGate\Core\Config\Definition\Section;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Node;

/**
 * Every setting of the config, with its type and what it can change, as the
 * parts of a layer declare them. Every layer is read through this
 * definition, and the JSON Schema is written from it, with the value each
 * setting takes when every layer leaves it out, so the two cannot drift
 * (ADR-0002).
 */
final readonly class Definition
{
    private const string SCHEMA = 'https://json-schema.org/draft/2020-12/schema';

    private const string PUBLISHED
        = 'https://raw.githubusercontent.com/nightworksio/php-mutation-gate/v1/resources/mutation-gate.schema.json';

    private const string DESCRIPTION
        = 'The config of nightworksio/mutation-gate: mutation-gate.json, .yaml, .yml or .neon.';

    /**
     * Every key a layer of config may write, read into the layer, at the origin its paths are named from.
     *
     * @return Section<Layer>
     */
    public static function config(Origin $origin): Section
    {
        $fields = [
            ...Setup::fields(),
            ...Floors::fields($origin),
            ...Reach::fields(),
            ...Shards::fields(),
            ...Ci::fields($origin),
            ...Proofs::fields(),
            ...Triage::fields(),
            ...Ignores::fields(),
            ...Reports::fields($origin),
            ...Badge::fields(),
            ...Pest::fields(),
            ...Local::fields(),
        ];

        return Section::of(static function (Node $config) use ($fields): Layer|Invalid {
            $layer = Layer::none();
            $readings = [];

            foreach ($fields as $field) {
                $reading = $field->read($config);
                $part = $reading->value();
                $layer = $part instanceof Layer ? $layer->over($part) : $layer;
                $readings[] = $reading;
            }

            $problems = Reading::problemsIn(...$readings);

            return $problems instanceof Invalid ? $problems : $layer;
        }, ...$fields);
    }

    /** A layer of config, read from its top, or every problem in it at once, each at its path. */
    public static function layer(Node $config, Origin $origin): Layer|Invalid
    {
        $reading = self::config($origin)->read($config);
        $layer = $reading->value();

        return $layer instanceof Layer ? $layer : Invalid::because(...$reading->problems());
    }

    /** The JSON Schema (draft 2020-12) of a config, as `config:schema` prints it and the package ships it. */
    public static function schema(): string
    {
        $origin = ProjectRoot::origin();

        return Json::object()
            ->with('$schema', self::SCHEMA)
            ->with('$id', self::PUBLISHED)
            ->with('title', 'mutation-gate')
            ->with('description', self::DESCRIPTION)
            ->merged(self::config($origin)->schemaUnder(Layer::standard()->written($origin)))
            ->pretty();
    }

    /** @return array<string, Effect> every setting, by its path, with what it can change */
    public static function effects(): array
    {
        return self::config(ProjectRoot::origin())->settings();
    }
}
