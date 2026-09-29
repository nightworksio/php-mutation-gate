<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

/** What a key that is not written means. */
enum Presence
{
    /** It takes its default. */
    case Defaulted;

    /** It is left out, and leaving it out means something of its own. */
    case Optional;

    /** It is a mistake. */
    case Required;

    /** It is an object whose every setting takes its default. */
    case Section;
}
