<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof\Key;

use NightWorksIO\MutationGate\Core\Composer\Manifest as Composer;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;

/**
 * A `composer.json` as a content key reads it: with its
 * `extra.mutation-gate` entry taken out, because the key holds what of the
 * gate's config affects results already. A manifest that is not a JSON
 * object is read as it is.
 */
final readonly class Manifest
{
    public static function digestOf(Contents $manifest): Digest
    {
        $decoded = Composer::decode($manifest, Path::root());
        $read = $decoded instanceof Composer ? $decoded->withoutGateEntry() : $manifest;

        return Digest::sha256Of($read->text());
    }
}
