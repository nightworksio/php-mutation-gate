<?php

declare(strict_types=1);

namespace Marked;

final readonly class Marked
{
    // @infection-ignore-all
    public function hidden(): int
    {
        return 1 + 1;
    }
}
