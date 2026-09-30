<?php

declare(strict_types=1);

namespace Library\Unexecutable;

/** An enum tests/Pest.php loads before Pest starts any plugin, so no override can replace it. */
enum Early: int
{
    case First = 1;
}
