<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Php;

use PhpParser\Node\Expr\Include_;

use function sprintf;

/** A kind of include, by the word PHP spells it with. */
enum IncludeKind: string
{
    case Include = 'include';
    case IncludeOnce = 'include_once';
    case Require = 'require';
    case RequireOnce = 'require_once';

    public static function of(Include_ $include): self
    {
        return match ($include->type) {
            Include_::TYPE_INCLUDE => self::Include,
            Include_::TYPE_INCLUDE_ONCE => self::IncludeOnce,
            Include_::TYPE_REQUIRE_ONCE => self::RequireOnce,
            default => self::Require,
        };
    }

    /** The include as a sentence names it: with its article, `an include` or `a require`. */
    public function said(): string
    {
        return match ($this) {
            self::Include, self::IncludeOnce => sprintf('an `%s`', $this->value),
            self::Require, self::RequireOnce => sprintf('a `%s`', $this->value),
        };
    }
}
