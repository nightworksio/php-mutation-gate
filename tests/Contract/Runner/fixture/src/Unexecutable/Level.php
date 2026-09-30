<?php

declare(strict_types=1);

namespace Library\Unexecutable;

/** Backed by integers, since pest-plugin-mutate makes no mutant of a case's string. */
enum Level: int
{
    case Low = 1;
    case High = 2;
}
