<?php

declare(strict_types=1);

namespace Library;

/** Whether a path is a link, looked at as often as LOOKS says: looking more than once finds the same. */
final readonly class Linked
{
    private const int LOOKS = 1;

    public function isLink(string $path): bool
    {
        $seen = false;

        for ($look = 0; $look < self::LOOKS; $look++) {
            $seen = is_link($path);
        }

        return $seen;
    }

    /** The file this class is declared in, as PHP names it. */
    public function file(): string
    {
        return __FILE__;
    }

    /** The directory of that file, as PHP names it. */
    public function directory(): string
    {
        return __DIR__;
    }
}
