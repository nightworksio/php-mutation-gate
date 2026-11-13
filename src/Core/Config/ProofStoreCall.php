<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_keys;
use function array_map;
use function implode;
use function is_string;
use function sprintf;

/**
 * A proof store as the builder chooses it: `Proofs::directory()`, `::s3()`,
 * `::gcs()` or `::azure()` for the built-in ones, and the adapter's own
 * call for any other.
 *
 * @internal
 */
final readonly class ProofStoreCall
{
    /** The proof store, by `Proofs::directory()`, `::s3()`, `::gcs()` or `::azure()` for the built-in ones. */
    public static function of(Choice $store): string
    {
        $options = $store->options();
        $texts = [];

        foreach ($options as $key) {
            $text = $options->text($key);
            $texts = is_string($text) ? [...$texts, $key->value() => $text] : $texts;
        }

        return match ($store->use()->value()) {
            BuiltinStore::Directory->value => sprintf(
                'Proofs::directory(%s)',
                PhpCalls::literals(...$texts),
            ),
            BuiltinStore::S3->value, BuiltinStore::Gcs->value, BuiltinStore::Azure->value => sprintf(
                'Proofs::%s(%s)',
                $store->use()->value(),
                implode(
                    ', ',
                    array_map(
                        static fn(string $option, string $value): string => sprintf(
                            '%s: %s',
                            $option,
                            PhpCalls::literal($value),
                        ),
                        array_keys($texts),
                        $texts,
                    ),
                ),
            ),
            default => PhpCalls::chosen($store, AdapterBuilder::Proofs),
        };
    }
}
