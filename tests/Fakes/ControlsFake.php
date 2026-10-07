<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Fakes;

use NightWorksIO\MutationGate\Core\Control\ControlRun;
use NightWorksIO\MutationGate\Core\Control\ControlRuns;
use NightWorksIO\MutationGate\Core\Control\Controls;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\OwnTime;

/** Unmutated controls as a fake runner runs them. */
final readonly class ControlsFake
{
    private function __construct()
    {
    }

    /** Each of these controls passing, its tests taking as long as the map timed them. */
    public static function passing(Controls $controls, CoverageMap $map): ControlRuns
    {
        $runs = ControlRuns::none();

        foreach ($controls as $control) {
            $runs = $runs->with($control, ControlRun::passed(OwnTime::of($map, $control->tests())));
        }

        return $runs;
    }
}
