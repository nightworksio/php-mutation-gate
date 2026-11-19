<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function sort;

/**
 * Where one file names a value's owner: its name, and `self` or `static`
 * inside the owner itself. Any use of an enum may read a case's backing
 * value, since `from()`, `tryFrom()`, `cases()` and serialisation all do, and
 * a reflection that makes an attribute names the owner of its arguments.
 *
 * @internal
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

        $own = [...$tokens->indicesOf(T_STATIC), ...$tokens->words()->placesOf(OwnMember::SELF)];
        sort($own);

        foreach ($own as $at) {
            $itself = ($tokens->is($at, T_STATIC) || OwnMember::isSelf($tokens, $at))
                && $source->classAround($at)->key() === $symbol->owner();
            $found = $itself ? $found->and(References::at(Site::in($source, $at))) : $found;
        }

        return $found;
    }
}
