<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

/** What leaving a setting out of a layer of config means. */
enum Presence
{
    /** Nothing: a later layer, or the setting's own default, gives it. */
    case Optional;

    /** It is a mistake. */
    case Required;

    /** It is an object whose every setting is left out. */
    case Section;
}
