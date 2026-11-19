<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof\Key;

use function count;
use function hash_update;

use HashContext;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\Format\Bytes;
use NightWorksIO\MutationGate\Core\Runner\Identity;

use function sort;
use function sprintf;

/**
 * How a key writes its fields into its hash: every field with its length
 * before it, and every list with its count, sorted where its order is not
 * its own, so no two sets of inputs hash alike (ADR-0007).
 *
 * @internal
 */
final readonly class KeyFields
{
    /** Fields as a key reads them, each written with its length in bytes before it, so no two lists write alike. */
    public static function framed(string ...$fields): string
    {
        $framed = '';

        foreach ($fields as $field) {
            $framed .= sprintf("%d:%s\n", Bytes::length($field), $field);
        }

        return $framed;
    }

    /** The runner's identity: the version and reference of every package it drives, by package. */
    public static function versions(HashContext $context, Identity $runner): void
    {
        $versions = [];
        $packages = [];

        foreach ($runner->versions() as $version) {
            $versions[$version->package()] = $version;
            $packages[] = $version->package();
        }

        sort($packages, SORT_STRING);
        hash_update($context, self::framed(sprintf('%d', count($packages))));

        foreach ($packages as $package) {
            $version = $versions[$package];
            hash_update($context, self::framed($version->package(), $version->version(), $version->reference()));
        }
    }

    /** Files by their digests, by path. */
    public static function fingerprints(HashContext $context, Fingerprints $fingerprints): void
    {
        $digests = [];
        $paths = [];

        foreach ($fingerprints as $fingerprint) {
            $digests[$fingerprint->path()->value()] = $fingerprint->digest()->value();
            $paths[] = $fingerprint->path()->value();
        }

        sort($paths, SORT_STRING);
        hash_update($context, self::framed(sprintf('%d', count($paths))));

        foreach ($paths as $path) {
            hash_update($context, self::framed($path, $digests[$path]));
        }
    }
}
