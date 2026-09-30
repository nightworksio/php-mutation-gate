<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use function array_is_list;
use function is_array;

/** A value as a problem message names what was found: `"80"`, `80`, `a list`, `an object`. */
final readonly class Got
{
    public static function of(mixed $value): string
    {
        return match (true) {
            ! is_array($value) => Json::encode($value),
            array_is_list($value) => 'a list',
            default => 'an object',
        };
    }
}
