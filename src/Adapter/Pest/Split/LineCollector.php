<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

/**
 * What collects the lines a stream's parent executes in one test where
 * `outbreak.so` does not record them: pcov, or one a test drives.
 *
 * @internal
 */
interface LineCollector
{
    /** Forgets every line collected so far. */
    public function clear(): void;

    /** Starts collecting. */
    public function start(): void;

    /** Stops collecting. */
    public function stop(): void;

    /**
     * Each file collected since it last cleared, with a mark for each line it
     * reports: 1 where a test executed it, -1 where none did.
     *
     * @return array<string, array<int, int>>
     */
    public function collect(): array;
}
