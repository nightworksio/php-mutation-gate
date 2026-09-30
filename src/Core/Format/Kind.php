<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Format;

/**
 * What a place in a decoded JSON text holds, as JSON names it. An empty `{}`
 * and an empty `[]` decode alike, so either is empty rather than a map or a
 * list, and a key that is not there holds nothing.
 */
enum Kind
{
    case Map;
    case List;
    case Empty;
    case Text;
    case Integer;
    case Number;
    case Boolean;
    case Null;
    case Nothing;
}
