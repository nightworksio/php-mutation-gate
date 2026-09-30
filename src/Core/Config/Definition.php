<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Config\Definition\BadgeKeys;
use NightWorksIO\MutationGate\Core\Config\Definition\CiKeys;
use NightWorksIO\MutationGate\Core\Config\Definition\FloorsKeys;
use NightWorksIO\MutationGate\Core\Config\Definition\IgnoresKeys;
use NightWorksIO\MutationGate\Core\Config\Definition\LocalKeys;
use NightWorksIO\MutationGate\Core\Config\Definition\PestKeys;
use NightWorksIO\MutationGate\Core\Config\Definition\ProofsKeys;
use NightWorksIO\MutationGate\Core\Config\Definition\ReachKeys;
use NightWorksIO\MutationGate\Core\Config\Definition\Reading;
use NightWorksIO\MutationGate\Core\Config\Definition\ReportsKeys;
use NightWorksIO\MutationGate\Core\Config\Definition\Section;
use NightWorksIO\MutationGate\Core\Config\Definition\SetupKeys;
use NightWorksIO\MutationGate\Core\Config\Definition\ShardsKeys;
use NightWorksIO\MutationGate\Core\Config\Definition\TriageKeys;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;

/**
 * Every setting of the config, with its type and what it can change, as the
 * keys of each part of a layer declare them. Every layer is read through this
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
            ...SetupKeys::fields($origin),
            ...FloorsKeys::fields($origin),
            ...ReachKeys::fields($origin),
            ...ShardsKeys::fields($origin),
            ...CiKeys::fields($origin),
            ...ProofsKeys::fields($origin),
            ...TriageKeys::fields(),
            ...IgnoresKeys::fields($origin),
            ...ReportsKeys::fields($origin),
            ...BadgeKeys::fields(),
            ...PestKeys::fields(),
            ...LocalKeys::fields(),
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

    /**
     * A layer built elsewhere, such as a loader's or a preset's, read again as a file at this origin writes it:
     * the definition judges every layer, however it was built, or none could be trusted to hold its values.
     */
    public static function judged(Layer $layer, Origin $origin): Layer|Invalid
    {
        return self::layer(Node::config($layer->written($origin)->line()), $origin);
    }

    /** The JSON Schema (draft 2020-12) of a config, as `config:schema` prints it and the package ships it. */
    public static function schema(): string
    {
        $origin = ProjectRoot::origin();

        return Json::object()
            ->with(Member::of('$schema', self::SCHEMA))
            ->with(Member::of('$id', self::PUBLISHED))
            ->with(Member::of('title', 'mutation-gate'))
            ->with(Member::of('description', self::DESCRIPTION))
            ->merged(self::config($origin)->schemaUnder(Layer::standard()->written($origin)))
            ->pretty();
    }

    /** @return array<string, Effect> every setting, by its path, with what it can change */
    public static function effects(): array
    {
        return self::config(ProjectRoot::origin())->settings();
    }
}
