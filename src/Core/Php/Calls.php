<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

/**
 * Where one file calls a plain function, whose parameter defaults each call
 * that leaves the parameter out reads: its name, as the file resolves it,
 * before `(`, and not as a method, a declaration or a class.
 */
final readonly class Calls
{
    /** What stands before a name followed by `(` that is no call of a plain function. */
    private const array NOT_A_CALL = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW];

    public static function of(Symbol $symbol, Source $source): References
    {
        $tokens = $source->tokens();
        $function = Names::of($symbol->owner());
        $found = References::none();

        foreach ($tokens->indicesOf(...Names::TOKENS) as $at) {
            $calls = $tokens->is($at + 1, '(')
                && ! $tokens->is($at - 1, ...self::NOT_A_CALL)
                && $source->scope()->resolve($tokens->text($at))->meet($function);
            $found = $calls ? $found->and(References::at(Site::in($source, $at))) : $found;
        }

        return $found;
    }
}
