<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof\Key;

use function count;
use function hash_update;
use function ksort;

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Fingerprints;

use const SORT_STRING;

use function sprintf;

/**
 * Everything a whole run's verdict reads, as one digest (ADR-0198): what
 * every proof's key reads before any coverage map (the gate's verdict code,
 * the config as it affects results, the runner's identity and its PHP, the
 * static analyser and what is installed), every file of the repository by
 * its content, the gate's release, the image the runner runs on and the
 * variables the config names as read. Two runs whose digests are equal
 * judge byte-identical inputs, so the verdict of one stands for the other;
 * any difference in any one of them makes another digest.
 *
 * @internal
 */
final readonly class RunInputs
{
    /** What the digest means; it changes whenever what it holds does, so an older one never matches a newer one. */
    public const string FORMAT = 'mutation-gate run inputs 1';

    /**
     * @param array<string, string> $image       what the runner's image says of itself, by name
     * @param array<string, string> $environment the value of each variable the config names as read, by name
     */
    public static function of(
        Digest $reads,
        Fingerprints $files,
        string $release,
        array $image,
        array $environment,
    ): Digest {
        $context = Digest::hashing();
        hash_update($context, KeyFields::framed(self::FORMAT, $reads->value(), $release));
        KeyFields::fingerprints($context, $files);
        hash_update($context, self::named('image', $image));
        hash_update($context, self::named('environment', $environment));

        return Digest::finished($context);
    }

    /** @param array<string, string> $values */
    private static function named(string $what, array $values): string
    {
        ksort($values, SORT_STRING);
        $framed = KeyFields::framed($what, sprintf('%d', count($values)));

        foreach ($values as $name => $value) {
            $framed .= KeyFields::framed($name, $value);
        }

        return $framed;
    }
}
