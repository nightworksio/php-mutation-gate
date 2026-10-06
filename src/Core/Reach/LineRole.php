<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Reach;

/** What a line of a CI definition, outside every block scalar, is to the value it starts. */
enum LineRole
{
    /** A key, or a list's entry, whose value is the node on the lines below it. */
    case Opens;

    /** A key, or a list's entry, whose value is written whole on the line. */
    case Holds;

    /** A key, or a list's entry, whose value is a block scalar: every line below it written more deeply. */
    case StartsBlock;

    /** A line whose role cannot be told without reading it as its runner does. */
    case Unread;
}
