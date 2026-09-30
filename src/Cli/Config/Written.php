<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Config;

use function array_key_exists;
use function is_array;
use function is_int;
use function is_string;

use NightWorksIO\MutationGate\Core\Config\Definition\At;
use NightWorksIO\MutationGate\Core\Config\Definition\Json;
use NightWorksIO\MutationGate\Core\Config\Document;
use NightWorksIO\MutationGate\Core\Config\Layers;

/**
 * A config as a file or a layer wrote it, before it is validated: the few
 * settings the gate reads first to know which layers to lay.
 */
final readonly class Written
{
    /** Whether a config chooses a runner, rather than leave it to zero-config, with or without its `withhold`. */
    public static function choosesRunner(Document $document): bool
    {
        $tree = self::decoded($document);

        return array_key_exists('runner', $tree) && ! Layers::onlyWithholds($tree['runner']);
    }

    /**
     * The presets a config file names, by the path it names each at: `preset` for one, `preset[1]` in a list.
     *
     * @return array<string, string>
     */
    public static function presets(Document $file): array
    {
        $tree = self::decoded($file);
        $value = array_key_exists('preset', $tree) ? $tree['preset'] : [];
        $presets = [];

        foreach (is_array($value) ? $value : [] as $index => $preset) {
            if (is_int($index) && is_string($preset)) {
                $presets[At::index('preset', $index)] = $preset;
            }
        }

        return is_string($value) ? ['preset' => $value] : $presets;
    }

    /**
     * A setting that is a string or a list of them, as a config wrote it before it is validated.
     *
     * @return list<string>
     */
    public static function strings(Document $document, string $key): array
    {
        $tree = self::decoded($document);
        $value = array_key_exists($key, $tree) ? $tree[$key] : [];
        $strings = [];

        foreach (is_array($value) ? $value : [$value] as $entry) {
            if (is_string($entry)) {
                $strings[] = $entry;
            }
        }

        return $strings;
    }

    /** @return array<mixed> */
    private static function decoded(Document $document): array
    {
        $tree = Json::decode($document->json());

        return is_array($tree) ? $tree : [];
    }
}
