<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;

use function sprintf;

use stdClass;

/**
 * Units' content keys as the gate's files write them: a map of unit path to
 * key, where a unit with no key holds why.
 *
 * @internal the shape of the plan and shard result files
 *
 * @phpstan-type Written array<string, string|array{unkeyed: string}>
 */
final readonly class KeysRecord
{
    private const string UNKEYED = 'unkeyed';

    /** @return Written|stdClass */
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
        $read = [];

        foreach ($keys->entries() as $unit => $key) {
            $unkeyed = $key->field(self::UNKEYED);
            $read[] = Keys::none()->with(
                Path::of(sprintf('%s', $unit)),
                $unkeyed->isPresent() ? Unkeyed::because($unkeyed->text()) : Digest::of($key->text()),
            );
        }

        return Keys::none()->and(...$read);
    }
}
