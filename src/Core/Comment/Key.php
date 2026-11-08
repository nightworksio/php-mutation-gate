<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Comment;

use function array_last;
use function explode;

/**
 * A `comment` key that takes words, by its name, which is the PHP builder's method too (ADR-0102), with the enum
 * its words are cases of.
 *
 * @internal
 */
enum Key: string
{
    case Sections = 'sections';
    case Open = 'open';
    case States = 'states';
    case On = 'on';
    case Lines = 'lines';
    case Detail = 'detail';
    case Mode = 'mode';
    case Passing = 'passing';

    /** @return class-string the enum its words are cases of */
    public function words(): string
    {
        return match ($this) {
            self::Sections, self::Open => Section::class,
            self::States => State::class,
            self::On => Occasion::class,
            self::Lines => Lines::class,
            self::Detail => Detail::class,
            self::Mode => Mode::class,
            self::Passing => Passing::class,
        };
    }

    /** The short name of the enum its words are cases of, as a PHP config imports it. */
    public function enum(): string
    {
        return array_last(explode('\\', $this->words()));
    }
}
