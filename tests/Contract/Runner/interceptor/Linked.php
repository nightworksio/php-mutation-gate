<?php

declare(strict_types=1);

namespace Library;

use function is_link;

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
}
