<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection\Import;

use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Import\Carried;
use NightWorksIO\MutationGate\Core\Import\Import;

use function sprintf;

/**
 * Every top-level key of Infection's config that no other part of an import
 * maps, and what became of it: a key the gate does not know stays, since it
 * does not read it.
 */
final readonly class Remaining
{
    private const string UNREAD = 'the gate does not read it';

    public static function of(Node $settings): Import
    {
        $import = Import::none();

        foreach (Lenient::entries($settings) as $key => $value) {
            $key = sprintf('%s', $key);
            $kept = Kept::named($key);
            $carried = $kept instanceof Kept ? $kept->carried() : Carried::stays($key, self::UNREAD);
            $import = Mapped::tryFrom($key) instanceof Mapped
                ? $import
                : $import->and(Import::of(Layer::none(), $carried));
        }

        return $import;
    }
}
