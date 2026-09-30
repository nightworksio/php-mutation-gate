<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function mb_strtolower;

/**
 * Where one file uses an enum, any use of which may read a case's backing
 * value, since `from()`, `tryFrom()`, `cases()` and serialisation all do: its
 * name, and `self` or `static` inside the enum itself.
 */
final readonly class Mentions
{
    public static function of(Symbol $symbol, Source $source): References
    {
        $tokens = $source->tokens();
        $found = References::none();

        foreach ($source->namesOf($symbol->owner()) as $at) {
            $found = $found->and(References::at(Site::in($source, $at)));
        }

        foreach ($tokens->indicesOf(T_STATIC, T_STRING) as $at) {
            $itself = ($tokens->is($at, T_STATIC) || mb_strtolower($tokens->text($at)) === 'self')
                && $source->classAround($at)->key() === $symbol->owner();
            $found = $itself ? $found->and(References::at(Site::in($source, $at))) : $found;
        }

        return $found;
    }
}
