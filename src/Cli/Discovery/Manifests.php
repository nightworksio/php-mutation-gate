<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Discovery;

use function array_filter;
use function array_is_list;
use function array_key_exists;
use function array_map;
use function array_values;
use function is_array;
use function is_string;
use function json_decode;

use NightWorksIO\MutationGate\Core\CannotJudge;

use function sprintf;

/**
 * The extension classes Composer manifests name under
 * `extra.mutation-gate.extensions`: the root `composer.json`, and every
 * package in `vendor/composer/installed.json`.
 */
final readonly class Manifests
{
    private const array WHERE_EXTENSIONS_ARE = ['extra', 'mutation-gate', 'extensions'];

    /**
     * What the root `composer.json` declares. A root without a name is said as its file.
     *
     * @return list<Declared>|CannotJudge
     */
    public static function root(string $json, string $file): array|CannotJudge
    {
        $manifest = json_decode($json, associative: true);

        if (! is_array($manifest)) {
            return CannotJudge::because(sprintf(
                '%s is not a JSON object, so the extensions it names cannot be read.',
                $file,
            ));
        }

        return self::declaredBy($manifest, $file);
    }

    /**
     * What every installed package declares, from Composer 2's `installed.json`.
     *
     * @return list<Declared>|CannotJudge
     */
    public static function installed(string $json, string $file): array|CannotJudge
    {
        $installed = json_decode($json, associative: true);

        if (
            ! is_array($installed)
            || ! array_key_exists('packages', $installed)
            || ! is_array($installed['packages'])
            || ! array_is_list($installed['packages'])
        ) {
            return CannotJudge::because(sprintf(
                '%s is not the list of installed packages Composer 2 writes, so their extensions cannot be read.',
                $file,
            ));
        }

        $declared = [];

        foreach ($installed['packages'] as $package) {
            $found = self::declaredBy($package, $file);

            if ($found instanceof CannotJudge) {
                return $found;
            }

            $declared = [...$declared, ...$found];
        }

        return $declared;
    }

    /**
     * What one manifest declares, said as the package it names, or as its
     * file where it names none.
     *
     * @return list<Declared>|CannotJudge
     */
    private static function declaredBy(mixed $manifest, string $file): array|CannotJudge
    {
        $origin = is_array($manifest) && array_key_exists('name', $manifest) && is_string($manifest['name'])
            ? $manifest['name']
            : $file;
        $classes = self::at($manifest, self::WHERE_EXTENSIONS_ARE);
        $names = is_array($classes) ? array_values(array_filter($classes, is_string(...))) : [];

        if ($names !== $classes) {
            return CannotJudge::because(sprintf(
                '%s names extra.mutation-gate.extensions, and it is not a list of class names.',
                $origin,
            ));
        }

        return array_map(static fn(string $class): Declared => new Declared($origin, $class), $names);
    }

    /**
     * What a decoded document holds under these keys, or an empty list where
     * it holds nothing there.
     *
     * @param list<string> $keys
     */
    private static function at(mixed $data, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (! is_array($data) || ! array_key_exists($key, $data)) {
                return [];
            }

            $data = $data[$key];
        }

        return $data;
    }
}
