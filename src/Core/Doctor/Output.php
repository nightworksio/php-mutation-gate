<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor;

/** How `doctor` writes its findings: for a person, or as the public JSON. */
enum Output: string
{
    case Text = 'text';
    case Json = 'json';
}
