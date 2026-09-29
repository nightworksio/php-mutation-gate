<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use stdClass;

/**
 * Units' content keys as the gate's files write them: a map of unit path to
 * key, where a unit with no key holds why.
 *
 * @internal the shape of the plan and shard result files
 */
final readonly class KeysRecord
{
    private const string UNKEYED = 'unkeyed';

    /** @return array<string, string|array<string, string>>|stdClass */
    public static function of(Keys $keys): array|stdClass
    {
        $written = [];

        foreach ($keys->units() as $unit) {
            $key = $keys->keyOf($unit);
            $written[$unit->value()] = $key instanceof Digest ? $key->value() : [self::UNKEYED => $key->why()];
        }

        return $written === [] ? new stdClass() : $written;
    }

    /** @throws NotInShape */
    public static function read(Node $keys): Keys
    {
        $read = Keys::none();

        foreach ($keys->entries() as $unit => $key) {
            $unkeyed = $key->field(self::UNKEYED);
            $read = $read->with(
                Path::of($unit),
                $unkeyed->isPresent() ? Unkeyed::because($unkeyed->text()) : Digest::of($key->text()),
            );
        }

        return $read;
    }
}
