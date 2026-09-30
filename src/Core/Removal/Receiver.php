<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Removal;

/** What a removed call statement calls its function on. */
enum Receiver
{
    /** The class the statement is in: `$this->m()`, `self::m()` or `static::m()`. */
    case TheCase;

    /** A class it names: `Name::m()`. */
    case AClass;

    /** Nothing: a function, `f()`. */
    case Nothing;
}
