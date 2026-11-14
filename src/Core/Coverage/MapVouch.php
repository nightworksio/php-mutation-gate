<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

/**
 * Whether an engine vouches for a coverage map's text (ADR-0118): that every
 * entry in it is one the careful reading keeps, every test listed once, so the
 * map is built straight from the decoded text and is the map the careful
 * reading builds. A text no engine vouches for is read carefully.
 */
enum MapVouch
{
    case Vouched;
    case Unvouched;
}
