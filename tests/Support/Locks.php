<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_keys;
use function array_map;
use function array_values;
use function hash;
use function json_encode;

use NightWorksIO\MutationGate\Core\File\Contents;

/** `composer.lock` files, as Composer writes them, of the packages a test pins. */
final readonly class Locks
{
    /**
     * A lock of these packages and dev packages, each name pinned at its
     * version, with these other keys besides.
     *
     * @param array<string, string> $packages
     * @param array<string, string> $dev
     * @param array<string, string|array<array-key, string|array<string, string>>> $other
     */
    public static function of(array $packages, array $dev = [], array $other = []): Contents
    {
        return Contents::of((string) json_encode([
            '_readme' => ['This file locks the dependencies of your project to a known state'],
            'content-hash' => hash('sha256', (string) json_encode([$packages, $dev, $other])),
            'packages' => self::pinned($packages),
            'packages-dev' => self::pinned($dev),
            ...$other,
        ]));
    }

    /**
     * @param  array<string, string>                        $packages
     * @return list<array{name: string, version: string}>
     */
    private static function pinned(array $packages): array
    {
        return array_map(
            static fn(string $name, string $version): array => ['name' => $name, 'version' => $version],
            array_keys($packages),
            array_values($packages),
        );
    }
}
