<?php

declare(strict_types=1);

namespace Library;

/** How large a title is lettered, by its length and its longest word. */
enum Lettering
{
    case Large;
    case Small;
    public const int LONGEST = 18;
    public const int WORD = 6;
    public static function for(int $length, int $word): self
    {
        return match (true) {
            $length <= self::LONGEST && $word <= self::WORD => self::Large,
            default => self::Small,
        };
    }
}
