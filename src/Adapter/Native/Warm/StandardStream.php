<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Native\Warm;

/**
 * The open files a process starts with rather than opens itself, by the numbers the system lists them under.
 *
 * @internal
 */
enum StandardStream: string
{
    case Input = '0';

    case Output = '1';

    case Error = '2';
}
