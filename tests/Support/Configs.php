<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_key_exists;

use DateTimeImmutable;

use function is_array;
use function is_string;
use function json_decode;
use function json_encode;

use NightWorksIO\MutationGate\Config\Gate;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Definition;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Origin;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Node;
use RuntimeException;

use function sprintf;

/** Configs as the tests write them, read the way the gate reads them. */
final readonly class Configs
{
    /** The instant every test validates at. */
    public const string NOW = '2026-09-30T12:00:00+00:00';

    /**
     * A config's JSON.
     *
     * @param array<mixed>|string $config a decoded config, or its JSON
     */
    public static function json(array|string $config): string
    {
        return is_string($config) ? $config : (string) json_encode($config, JSON_UNESCAPED_SLASHES);
    }

    /**
     * One layer of config, read as a file at this origin is.
     *
     * @param array<mixed>|string $config
     */
    public static function layer(array|string $config, Origin $origin = new ProjectRoot()): Layer|Invalid
    {
        return Definition::layer(Node::config(self::json($config)), $origin);
    }

    /**
     * One layer of config, which the test writes validly.
     *
     * @param array<mixed>|string $config
     */
    public static function valid(array|string $config): Layer
    {
        $layer = self::layer($config);

        return $layer instanceof Layer
            ? $layer
            : throw new RuntimeException(sprintf('The layer is invalid: %s', json_encode(self::problems($layer))));
    }

    /**
     * A config as the only layer, settled over every default.
     *
     * @param array<mixed>|string $config
     */
    public static function validated(array|string $config): Settings|Invalid
    {
        $layer = self::layer($config);

        return $layer instanceof Layer ? Settings::settled($layer, new DateTimeImmutable(self::NOW)) : $layer;
    }

    /** @param array<mixed>|string $config */
    public static function settings(array|string $config): Settings
    {
        $settings = self::validated($config);

        return $settings instanceof Settings
            ? $settings
            : throw new RuntimeException(sprintf('The config is invalid: %s', json_encode(self::problems($settings))));
    }

    /** The config a PHP builder writes, decoded. */
    public static function written(Gate $gate): mixed
    {
        return json_decode($gate->written()->line(), associative: true);
    }

    /** What a layer writes, decoded. */
    public static function decoded(Layer $layer, Origin $origin = new ProjectRoot()): mixed
    {
        return json_decode($layer->written($origin)->line(), associative: true);
    }

    /** The effective config, as `config:show` prints it as JSON. */
    public static function effective(Settings $settings): string
    {
        return $settings->effective()->written(ProjectRoot::origin())->pretty();
    }

    /** An adapter's options, as a config that writes them is read. */
    public static function options(string $json): Json
    {
        $options = Node::config($json);

        return $options->kind() === Kind::Empty ? Json::object() : $options->value();
    }

    /** What the effective config shows under these keys. */
    public static function shown(Settings $settings, string ...$keys): mixed
    {
        $shown = self::decoded($settings->effective());

        foreach ($keys as $key) {
            $shown = is_array($shown) && array_key_exists($key, $shown)
                ? $shown[$key]
                : throw new RuntimeException($key);
        }

        return $shown;
    }

    /**
     * Every problem, as `path: message`.
     *
     * @return list<string>
     */
    public static function problems(Settings|Layer|Invalid|CannotJudge $outcome): array
    {
        if ($outcome instanceof CannotJudge) {
            return [$outcome->why()];
        }

        $problems = [];

        foreach ($outcome instanceof Invalid ? $outcome : [] as $problem) {
            $problems[] = sprintf('%s: %s', $problem->path(), $problem->message());
        }

        return $problems;
    }
}
