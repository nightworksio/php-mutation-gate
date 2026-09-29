<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof\Key;

use function array_key_exists;
use function is_array;
use function json_decode;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Format\Json;

/**
 * A `composer.json` as a content key reads it: with its
 * `extra.mutation-gate` entry taken out, because the key holds what of the
 * gate's config affects results already. A manifest that is not a JSON
 * object is read as it is.
 */
final readonly class Manifest
{
    private const string EXTRA = 'extra';

    private const string GATE = 'mutation-gate';

    public static function digestOf(Contents $manifest): Digest
    {
        $decoded = json_decode($manifest->text(), associative: true);

        if (! is_array($decoded) || ! array_key_exists(self::EXTRA, $decoded) || ! is_array($decoded[self::EXTRA])) {
            return Digest::sha256Of($manifest->text());
        }

        unset($decoded[self::EXTRA][self::GATE]);

        return Digest::sha256Of(Json::encode($decoded));
    }
}
