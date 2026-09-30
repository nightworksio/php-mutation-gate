<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Discovery;

use function dirname;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\Installed;
use NightWorksIO\MutationGate\Core\Composer\Manifest;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;

/**
 * The extension classes Composer manifests name under
 * `extra.mutation-gate.extensions`: the root `composer.json`, and every
 * package in `vendor/composer/installed.json`.
 */
final readonly class Manifests
{
    /**
     * What the root `composer.json` declares. A root without a name is said as its file.
     *
     * @return list<Declared>|CannotJudge
     */
    public static function root(string $json, string $file): array|CannotJudge
    {
        $manifest = Manifest::decode(Contents::of($json), Path::of(dirname($file)));

        return $manifest instanceof CannotJudge ? $manifest : self::declaredBy($manifest);
    }

    /**
     * What every installed package declares, from Composer 2's `installed.json`.
     *
     * @return list<Declared>|CannotJudge
     */
    public static function installed(string $json, string $file): array|CannotJudge
    {
        $installed = Installed::decode(Contents::of($json), Path::of($file));

        if ($installed instanceof CannotJudge) {
            return $installed;
        }

        $declared = [];

        foreach ($installed as $package) {
            $found = self::declaredBy($package);

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
    private static function declaredBy(Manifest $manifest): array|CannotJudge
    {
        $classes = $manifest->gate()->extensions();

        if ($classes instanceof CannotJudge) {
            return $classes;
        }

        $declared = [];

        foreach ($classes as $class) {
            $declared[] = new Declared($manifest->origin(), $class);
        }

        return $declared;
    }
}
