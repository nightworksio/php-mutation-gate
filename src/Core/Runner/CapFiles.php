<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\File\DiskPath;

/**
 * The files of a run's memory cap (ADR-0004, decision 9): an ini file in a
 * directory of the gate's own, which every PHP process of the run scans. A
 * runner adapter says where the directory is; these write and remove it.
 */
interface CapFiles
{
    /**
     * Whether the cap's ini is written, whole, into the directory, fresh: made
     * where it is not, emptied of the files an earlier run left. A link at
     * any level from the gate's directory down to it, or anything but a
     * file in it, refuses it.
     */
    public function written(DiskPath $workspace, DiskPath $directory, CapIni $ini): bool;

    /**
     * Removes the directory once the run that scans it is done. Where
     * something made a directory inside it, its files are removed and it is
     * left, for the next run to refuse.
     */
    public function removed(DiskPath $directory): void;
}
