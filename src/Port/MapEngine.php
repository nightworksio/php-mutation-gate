<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Port;

use NightWorksIO\MutationGate\Core\Coverage\MapSlices;
use NightWorksIO\MutationGate\Core\Coverage\MapVouch;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Written;

/**
 * An engine that takes a coverage map's bulk off the gate's PHP (ADR-0118).
 * Whatever it answers, the gate's output is the output it would have had
 * without it: a map it vouches for is the map the careful reading builds, and
 * a slice it writes holds the text the gate writes. Where it declines, the
 * gate does the work itself, as it does with no engine at all.
 */
interface MapEngine
{
    /**
     * Whether it vouches for a coverage map's text: that every entry in it is
     * one the careful reading keeps, every test listed once, so the map can be
     * built straight from the decoded text. It vouches for no text it is not
     * sure of.
     */
    public function vouches(Contents $text): MapVouch;

    /**
     * Each slice of the whole map at a path of the project written, gzipped,
     * holding every test of the whole map and the lines and methods of its
     * files, as the gate writes the map `onlyFor()` them; or why none was,
     * and the gate writes them itself. The whole map is one the gate wrote.
     */
    public function split(Path $whole, MapSlices $slices): Written|NotWritten;
}
