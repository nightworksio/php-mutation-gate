<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Configurable;
use NightWorksIO\MutationGate\Port\Reporter;

/** A reporter whose named constructor builds something that is not a reporter. */
final readonly class MisbuiltReporter implements Configurable, Reporter
{
    public static function fromOptions(Options $options): Configurable
    {
        return new NotAReporter();
    }

    public function report(Verdict $verdict): Written
    {
        return Written::to('nowhere');
    }
}
