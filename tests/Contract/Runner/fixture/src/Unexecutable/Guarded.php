<?php

declare(strict_types=1);

namespace Library\Unexecutable;

/** A constant held by a group whose test does not assert it, while a test outside the group does. */
final class Guarded
{
    public const SEEN = 4;
}
