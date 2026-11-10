<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_key_exists;
use function in_array;

/**
 * What a split stream is told to do (ADR-0117), read from the plan Pest's own
 * process wrote: where it writes its results, each site's mutant, likely
 * killer and variables, the source file each schema stands for, each test's
 * own time by the coverage map, how many children it holds at once and each
 * child's limit; and, in a run that plans without a fresh map, where the
 * parent records the lines each test executed (ADR-0135).
 *
 * @internal
 */
final readonly class StreamPlan
{
    /**
     * @param array<string, array<int, array{string, string, array<string, string>}>> $sites each site's mutant, killer
     *                                                                                        and variables, by file key
     *                                                                                        and switch id
     * @param array<string, string>                                                   $paths each schema's source file,
     *                                                                                        by its file key
     * @param array<string, float>                                                    $times each test's own seconds
     */
    public function __construct(
        public string $results,
        public array $sites,
        public array $paths,
        public array $times,
        public int $children,
        public ChildLimit $limit,
        public string $recording = '',
        public bool $bootOnce = false,
        public bool $reconnect = false,
    ) {
    }

    /**
     * The mutants of each schema whose source file this process loaded, as
     * these files say it did, before the schema could be served in its place.
     *
     * @param  list<string> $loaded
     * @return list<string>
     */
    public function unservedIn(array $loaded): array
    {
        $unserved = [];

        foreach ($this->paths as $key => $path) {
            $loadedFirst = in_array($path, $loaded, strict: true) && array_key_exists($key, $this->sites);
            $sites = $loadedFirst ? $this->sites[$key] : [];

            foreach ($sites as [$mutant]) {
                $unserved[] = $mutant;
            }
        }

        return $unserved;
    }
}
