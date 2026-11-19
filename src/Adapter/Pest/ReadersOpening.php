<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function gc_disable;
use function gc_enable;
use function gc_enabled;

use NightWorksIO\MutationGate\Adapter\Pest\Unexecutable\Selector;
use NightWorksIO\MutationGate\Core\Config\Toggle;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\ShardOpening;
use NightWorksIO\MutationGate\Core\Test\TestCases;

/**
 * The map a patched shard opens on, readied once as the plan is made, for
 * the files of every shard at once (ADR-0163): the plan's map of those
 * files, with the lines of each value they declare covered by the tests
 * that read it (ADR-0037), and each line of a match's arms by the tests of
 * the arms after it too. None where the shards open on their own suite;
 * under `pest.valueReaders: false`, where a shard opens on its map as it is
 * handed; or under `pest.readyAtPlan: false`, where each shard readies its
 * own. It is built with PHP's cycle collector paused: the build makes
 * millions of objects, none garbage before it ends, and each pass of the
 * collector would walk the plan's whole heap and free nothing.
 *
 * @internal
 */
final readonly class ReadersOpening implements ShardOpening
{
    public function __construct(private Project $project, private Patching $patching)
    {
    }

    public function readied(CoverageMap $whole, Paths $files, TestCases $cases): CoverageMap|NotGiven
    {
        $steps = $this->patching->steps();
        $readies = $this->patching->isOn()
            && $steps->on(Toggle::PestValueReaders)
            && $steps->on(Toggle::PestReadyAtPlan);

        if (! $readies) {
            return NotGiven::value();
        }

        $collecting = gc_enabled();
        gc_disable();

        try {
            return Selector::over($this->project, new HandedOver($whole, $this->project), $files, $cases)
                ->opened($files, $whole->onlyFor($files));
        } finally {
            $collecting ? gc_enable() : null;
        }
    }
}
