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
use NightWorksIO\MutationGate\Core\Config\Document;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Config\Validator;
use RuntimeException;

use function sprintf;

/** Configs as the tests write them, read the way the gate reads them. */
final readonly class Configs
{
    /** The instant every test validates at. */
    public const string NOW = '2026-09-30T12:00:00+00:00';

    /** @param array<mixed>|string $config a decoded config, or its JSON */
    public static function document(array|string $config): Document
    {
        $json = is_string($config) ? $config : (string) json_encode($config, JSON_UNESCAPED_SLASHES);
        $document = Document::ofJson($json);

        return $document instanceof Document ? $document : throw new RuntimeException($document->why());
    }

    /** @param array<mixed>|string $config */
    public static function validated(array|string $config): Settings|Invalid
    {
        return new Validator(new DateTimeImmutable(self::NOW))->validate(self::document($config));
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
        $document = $gate->document();

        return $document instanceof Document ? json_decode($document->json(), associative: true) : $document;
    }

    /** What the effective config shows under these keys. */
    public static function shown(Settings $settings, string ...$keys): mixed
    {
        $shown = json_decode($settings->effective(), associative: true);

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
    public static function problems(Settings|Invalid|CannotJudge $outcome): array
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
