<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

/**
 * How Pest's warm workers boot, compile and serve each run: whether the
 * verification tail's workers boot the project (ADR-0075), whether the
 * workers compile through opcache (ADR-0088), whether each file's mutants are
 * compiled into one schema and the mutants' workers boot the project
 * (ADR-0091), and whether a shard's chunks keep their mutants' workers
 * (ADR-0023): keys of `pest`.
 */
final readonly class PestWorkers
{
    /** The key, and the Pest runner's option, that says whether the tail's workers boot the project. */
    public const string BOOT_TAIL = 'bootTail';

    /** The key, and the Pest runner's option, that says whether Pest's warm workers compile through opcache. */
    public const string WORKER_OPCACHE = 'workerOpcache';

    /** The key, and the Pest runner's option, that says whether each file's mutants are compiled into a schema. */
    public const string SCHEMATA = 'schemata';

    /** The key, and the Pest runner's option, that says whether mutants' workers boot the project. */
    public const string BOOT_MUTANTS = 'bootMutants';

    /** The key, and the Pest runner's option, that says whether a shard's chunks keep their mutants' workers. */
    public const string BOOT_CHUNKS = 'bootChunks';

    private function __construct(
        private bool|Absent $bootTail,
        private bool|Absent $workerOpcache,
        private bool|Absent $schemata,
        private bool|Absent $bootMutants,
        private bool|Absent $bootChunks,
    ) {
    }

    public static function of(
        bool|Absent $bootTail = new Absent(),
        bool|Absent $workerOpcache = new Absent(),
        bool|Absent $schemata = new Absent(),
        bool|Absent $bootMutants = new Absent(),
        bool|Absent $bootChunks = new Absent(),
    ): self {
        return new self($bootTail, $workerOpcache, $schemata, $bootMutants, $bootChunks);
    }

    /** These settings, with what a later layer sets laid over them. */
    public function over(self $later): self
    {
        return new self(
            Absent::laid($this->bootTail, $later->bootTail),
            Absent::laid($this->workerOpcache, $later->workerOpcache),
            Absent::laid($this->schemata, $later->schemata),
            Absent::laid($this->bootMutants, $later->bootMutants),
            Absent::laid($this->bootChunks, $later->bootChunks),
        );
    }

    /**
     * Whether the verification tail's Pest workers boot the project, every
     * test file loaded, before they fork its runs that load every test file
     * (ADR-0075); false by default.
     */
    public function bootTail(): bool
    {
        return ! $this->bootTail instanceof Absent && $this->bootTail;
    }

    /**
     * Whether Pest's warm workers compile through opcache, shared by every
     * run they fork, with each file the run mutates and each copy it serves
     * kept out of it (ADR-0088); true by default.
     */
    public function workerOpcache(): bool
    {
        return $this->workerOpcache instanceof Absent || $this->workerOpcache;
    }

    /**
     * Whether each file's mutants are compiled into one schema, every mutant
     * a switch on the original's line, which a mutant's own run serves in its
     * file's place (ADR-0091); true by default.
     */
    public function schemata(): bool
    {
        return $this->schemata instanceof Absent || $this->schemata;
    }

    /**
     * Whether the workers of mutants' own runs boot the project, no test file
     * loaded and each file's schema served, before they fork, each child
     * booting Pest's plugins and loading its own test files (ADR-0091); true
     * by default.
     */
    public function bootMutants(): bool
    {
        return $this->bootMutants instanceof Absent || $this->bootMutants;
    }

    /**
     * Whether a shard's chunks keep the warm workers of their mutants' own
     * runs for the next chunk, where those workers boot nothing of the
     * project and compile without opcache, so every chunk forks from an equal
     * state (ADR-0023); false by default.
     */
    public function bootChunks(): bool
    {
        return ! $this->bootChunks instanceof Absent && $this->bootChunks;
    }

    /** The options these settings give the Pest runner, as they hold. */
    public function options(): Json
    {
        return Json::object(
            Member::of(self::BOOT_TAIL, $this->bootTail()),
            Member::of(self::WORKER_OPCACHE, $this->workerOpcache()),
            Member::of(self::SCHEMATA, $this->schemata()),
            Member::of(self::BOOT_MUTANTS, $this->bootMutants()),
            Member::of(self::BOOT_CHUNKS, $this->bootChunks()),
        );
    }

    /** The keys of `pest` a layer sets of these, as it writes them. */
    public function written(): Json
    {
        return Json::object(
            Member::of(self::BOOT_TAIL, $this->bootTail),
            Member::of(self::WORKER_OPCACHE, $this->workerOpcache),
            Member::of(self::SCHEMATA, $this->schemata),
            Member::of(self::BOOT_MUTANTS, $this->bootMutants),
            Member::of(self::BOOT_CHUNKS, $this->bootChunks),
        );
    }

    /** The builder's calls that set these as a layer sets them. */
    public function php(): PhpCalls
    {
        return PhpCalls::flag($this->bootTail, 'PestWorkers::bootingTail()', 'PestWorkers::tailUnbooted()')
            ->and(PhpCalls::flag(
                $this->workerOpcache,
                'PestWorkers::cachingInWorkers()',
                'PestWorkers::uncachedInWorkers()',
            ))
            ->and(PhpCalls::flag($this->schemata, 'PestWorkers::compilingSchemata()', 'PestWorkers::withoutSchemata()'))
            ->and(PhpCalls::flag($this->bootMutants, 'PestWorkers::bootingMutants()', 'PestWorkers::mutantsUnbooted()'))
            ->and(PhpCalls::flag($this->bootChunks, 'PestWorkers::bootingChunks()', 'PestWorkers::chunksUnbooted()'));
    }
}
