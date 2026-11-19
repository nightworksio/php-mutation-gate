<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Outbreak;

use function array_map;

use Closure;

use function json_encode;

use NightWorksIO\MutationGate\Core\Coverage\MapSlices;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\JsonText;

/**
 * What the gate asks `outbreak split` of a whole map, as compact JSON on the
 * binary's input: the whole map, and each slice it writes, with the files the
 * slice keeps, at their paths on disk.
 *
 * @internal
 */
final readonly class SplitAsked
{
    /** @param Closure(Path): string $absolute where a path of the project is on disk */
    public static function of(Path $whole, MapSlices $slices, Closure $absolute): string
    {
        $asked = [];

        foreach ($slices as $slice) {
            $asked[] = [
                'into' => $absolute($slice->into()),
                'files' => array_map(static fn(Path $file): string => $file->value(), [...$slice->files()]),
            ];
        }

        return json_encode(['whole' => $absolute($whole), 'slices' => $asked], JsonText::FLAGS);
    }
}
