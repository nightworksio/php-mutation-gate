<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function is_string;

use NightWorksIO\MutationGate\Core\Hold\Holding;
use NightWorksIO\MutationGate\Core\Hold\Holdings;

use function preg_match;
use function preg_match_all;
use function sprintf;

/**
 * Every `#[Holds]` a PHP file writes, read from its tokens joined by spaces,
 * with the class or method it stands on. The file is never loaded.
 */
final readonly class HoldsAttributes
{
    /** The attribute, as the package declares it. */
    private const string HOLDS = 'NightWorksIO\MutationGate\Attribute\Holds';

    /**
     * In the order they are written: attribute groups with the modifiers after
     * them and the class or method they stand on, and every other class, which
     * the methods after it belong to.
     */
    private const string DECLARATIONS = <<<'REGEX'
        ~(?<attributes>(?:\#\[\s.*?\s\]\s)+)
        (?:(?:abstract|final|readonly|public|protected|private|static)\s)*
        (?<kind>class|function)\s(?:&\s)?(?<name>\w+)
        |(?<!new\s)(?<!::\s)\b(?:class|trait|enum)\s(?<type>\w+)~sux
        REGEX;

    /** An attribute with its first argument, named `path` or not. */
    private const string ATTRIBUTE = '~(?<name>\\\\?[A-Za-z_][\w\\\\]*)\s\(\s(?:path\s:\s)?(?<argument>.*?)\s[,)]~su';

    /** A string literal in single or double quotes, and what it holds. */
    private const string LITERAL = '~^([\'"])(?<text>.*)\1$~su';

    public static function in(string $spelt, Scope $scope): Holdings
    {
        preg_match_all(self::DECLARATIONS, $spelt, $declarations, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        $holdings = Holdings::none();
        $class = '';

        foreach ($declarations as $declaration) {
            $isClass = $declaration['kind'] === 'class';
            $named = $isClass ? $declaration['name'] : $declaration['type'];
            $class = is_string($named) ? $scope->declared($named) : $class;
            $holder = $isClass ? $class : sprintf('%s::%s', $class, $declaration['name']);
            $holdings = is_string($declaration['attributes'])
                ? $holdings->merge(self::heldBy($declaration['attributes'], $holder, $scope))
                : $holdings;
        }

        return $holdings;
    }

    /** Every `#[Holds]` among some attribute groups, held by one class or method. */
    private static function heldBy(string $attributes, string $holder, Scope $scope): Holdings
    {
        preg_match_all(self::ATTRIBUTE, $attributes, $found, PREG_SET_ORDER);
        $holdings = Holdings::none();

        foreach ($found as $attribute) {
            $holdings = $scope->resolve($attribute['name'])->meet(Names::of(self::HOLDS))
                ? $holdings->with(Holding::byAttribute(self::pathIn($attribute['argument']), $holder))
                : $holdings;
        }

        return $holdings;
    }

    /** The path an argument spells: what a string literal holds, or the argument as written. */
    private static function pathIn(string $argument): string
    {
        return preg_match(self::LITERAL, $argument, $literal) === 1 ? $literal['text'] : $argument;
    }
}
