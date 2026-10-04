<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Opcache;

/** Why opcache gave no opcodes of a program, which then proves nothing. */
enum Uncompiled
{
    /** PHP could not compile the program, or the child that compiled it stopped. */
    case Failed;
    /** The child compiled the program, and opcache dumped nothing of it. */
    case NoOpcache;
}
