<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

/** An operating system family, as PHP's `PHP_OS_FAMILY` names it. */
enum OsFamily: string
{
    case Linux = 'Linux';

    case Darwin = 'Darwin';

    case Windows = 'Windows';

    case Bsd = 'BSD';

    case Solaris = 'Solaris';

    case Unknown = 'Unknown';
}
