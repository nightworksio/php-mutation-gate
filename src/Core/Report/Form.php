<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

/** How a command that reports, such as `doctor` or `explain`, writes: for a person, or as its public JSON. */
enum Form: string
{
    case Text = 'text';
    case Json = 'json';
}
