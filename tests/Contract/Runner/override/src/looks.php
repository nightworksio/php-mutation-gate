<?php

declare(strict_types=1);

namespace Library;

/** Whether a path is a link, looked at as often as asked: looking more than once finds the same. */
function looksLinked(
    string $path,
    int $looks = 1,
): bool {
    $seen = false;

    for ($look = 0; $look < $looks; $look++) {
        $seen = is_link($path);
    }

    return $seen;
}
