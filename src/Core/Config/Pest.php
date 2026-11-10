<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Test\Group;

use function sprintf;

/**
 * The optional Pest patches, the tests that show they work (ADR-0004),
 * whether the verification tail's workers boot the project (ADR-0075),
 * how Pest's warm workers compile, serve and boot each mutant's run
 * (ADR-0088, ADR-0091), and what becomes of a mutant whose file its own run
 * loaded first (ADR-0148): `pest`; and whether a patched run judges its
 * mutants in split streams (ADR-0117): `splitStream`.
 */
final readonly class Pest implements Part
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

    /** The key and option that say what a kill in a narrowed run needs (ADR-0142). */
    public const string NARROWED_KILLS = 'narrowedKills';

    /** The key and option that say what a run selects where its filter cannot be passed (ADR-0138). */
    public const string LONG_FILTER = 'longFilter';

    /** The key and option that say what becomes of a mutant whose file its own run loaded first (ADR-0148). */
    public const string PRELOADED = 'preloaded';

    private const string CANARY = 'mutation-canary';

    private function __construct(
        private bool|Absent $patch,
        private Group|Absent $canary,
        private bool|Absent $bootTail,
        private bool|Absent $workerOpcache,
        private bool|Absent $schemata,
        private bool|Absent $bootMutants,
        private SplitStream $splitStream,
        private NarrowedKillTrust|Absent $narrowedKills,
        private LongFilter|Absent $longFilter,
        private bool|Absent $bootChunks,
        private Preloaded|Absent $preloaded,
    ) {
    }

    public static function of(
        bool|Absent $patch = new Absent(),
        Group|Absent $canary = new Absent(),
        bool|Absent $bootTail = new Absent(),
        bool|Absent $workerOpcache = new Absent(),
        bool|Absent $schemata = new Absent(),
        bool|Absent $bootMutants = new Absent(),
        NarrowedKillTrust|Absent $narrowedKills = new Absent(),
        LongFilter|Absent $longFilter = new Absent(),
        bool|Absent $bootChunks = new Absent(),
        Preloaded|Absent $preloaded = new Absent(),
    ): self {
        return new self(
            $patch,
            $canary,
            $bootTail,
            $workerOpcache,
            $schemata,
            $bootMutants,
            SplitStream::of(),
            $narrowedKills,
            $longFilter,
            $bootChunks,
            $preloaded,
        );
    }

    /** What a layer sets of `splitStream`, and of no other Pest setting. */
    public static function splitting(SplitStream $splitStream): self
    {
        $none = new Absent();

        return new self($none, $none, $none, $none, $none, $none, $splitStream, $none, $none, $none, $none);
    }

    public static function none(): self
    {
        return self::of();
    }

    public static function standard(): self
    {
        $none = self::none();

        return self::of(
            $none->patch(),
            $none->canary(),
            $none->bootTail(),
            $none->workerOpcache(),
            $none->schemata(),
            $none->bootMutants(),
            $none->narrowedKills(),
            $none->longFilter(),
            $none->bootChunks(),
            $none->preloaded(),
        )->over(self::splitting(SplitStream::standard()));
    }

    public function over(Part $later): self
    {
        return $later instanceof self
            ? new self(
                Absent::laid($this->patch, $later->patch),
                Absent::laid($this->canary, $later->canary),
                Absent::laid($this->bootTail, $later->bootTail),
                Absent::laid($this->workerOpcache, $later->workerOpcache),
                Absent::laid($this->schemata, $later->schemata),
                Absent::laid($this->bootMutants, $later->bootMutants),
                $this->splitStream->over($later->splitStream),
                Absent::laid($this->narrowedKills, $later->narrowedKills),
                Absent::laid($this->longFilter, $later->longFilter),
                Absent::laid($this->bootChunks, $later->bootChunks),
                Absent::laid($this->preloaded, $later->preloaded),
            )
            : $this;
    }

    /** `pest.narrowedKills`: what a kill in a narrowed run needs before it counts; `vouch` unless a layer says. */
    public function narrowedKills(): NarrowedKillTrust
    {
        return $this->narrowedKills instanceof NarrowedKillTrust ? $this->narrowedKills : NarrowedKillTrust::Vouch;
    }

    /**
     * `pest.longFilter`: what a run selects where its filter cannot be passed; `files` unless a layer says, until
     * ADR-0138's proof moves the default to `set`.
     */
    public function longFilter(): LongFilter
    {
        return $this->longFilter instanceof LongFilter ? $this->longFilter : LongFilter::Files;
    }

    /**
     * `pest.preloaded`: what becomes of a mutant whose own run had loaded its file before the mutant was in place;
     * `fresh` unless a layer says (ADR-0148).
     */
    public function preloaded(): Preloaded
    {
        return $this->preloaded instanceof Preloaded ? $this->preloaded : Preloaded::Fresh;
    }

    /** Whether the optional Pest patches are applied. */
    public function patch(): bool
    {
        return ! $this->patch instanceof Absent && $this->patch;
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

    /** `splitStream`: whether, and how, a patched run judges its mutants in split streams (ADR-0117). */
    public function splitStream(): SplitStream
    {
        return $this->splitStream;
    }

    /**
     * Whether a run under this runner judges its mutants in split streams:
     * the Pest runner, patched and compiling schemata, where
     * `splitStream.enabled` says so (ADR-0117).
     */
    public function splits(Choice $runner): bool
    {
        return $runner->use()->value() === BuiltinRunner::Pest->value
            && $this->patch()
            && $this->schemata()
            && $this->splitStream->enabled();
    }

    /**
     * Whether a run under this runner splits its streams and cuts its test
     * files across its shards, as `splitStream.sharding: tests` asks
     * (ADR-0117).
     */
    public function splitsByTests(Choice $runner): bool
    {
        return $this->splits($runner) && $this->splitStream->sharding() === StreamSharding::Tests;
    }

    /** The group of tests that shows the patches work. */
    public function canary(): Group
    {
        return $this->canary instanceof Group ? $this->canary : Group::named(self::CANARY);
    }

    /**
     * The runner a config chooses, with these settings beneath the options
     * it gives the Pest runner, so `pest.patch` reaches the runner it is
     * for; any other runner as it is.
     */
    public function beneath(Choice $runner): Choice
    {
        $options = Json::object(
            Member::of('patch', $this->patch()),
            Member::of('canary', $this->canary()->name()),
            Member::of(self::BOOT_TAIL, $this->bootTail()),
            Member::of(self::WORKER_OPCACHE, $this->workerOpcache()),
            Member::of(self::SCHEMATA, $this->schemata()),
            Member::of(self::BOOT_MUTANTS, $this->bootMutants()),
            Member::of(self::BOOT_CHUNKS, $this->bootChunks()),
            Member::of(self::NARROWED_KILLS, $this->narrowedKills()->value),
            Member::of(self::LONG_FILTER, $this->longFilter()->value),
            Member::of(self::PRELOADED, $this->preloaded()->value),
            $this->splitStream->option(),
        );

        return $runner->use()->value() === BuiltinRunner::Pest->value
            ? Choice::of($runner->use()->value(), $runner->options()->over($options))
            : $runner;
    }

    public function written(PathOrigin $origin): Json
    {
        return Json::object(
            Member::unlessEmpty(
                'pest',
                Json::object(
                    Member::of('patch', $this->patch),
                    Member::of('canary', $this->canary instanceof Group ? $this->canary->name() : $this->canary),
                    Member::of(self::BOOT_TAIL, $this->bootTail),
                    Member::of(self::WORKER_OPCACHE, $this->workerOpcache),
                    Member::of(self::SCHEMATA, $this->schemata),
                    Member::of(self::BOOT_MUTANTS, $this->bootMutants),
                    Member::of(self::BOOT_CHUNKS, $this->bootChunks),
                    Member::of(
                        self::NARROWED_KILLS,
                        $this->narrowedKills instanceof NarrowedKillTrust
                            ? $this->narrowedKills->value
                            : $this->narrowedKills,
                    ),
                    Member::of(
                        self::LONG_FILTER,
                        $this->longFilter instanceof LongFilter ? $this->longFilter->value : $this->longFilter,
                    ),
                    Member::of(
                        self::PRELOADED,
                        $this->preloaded instanceof Preloaded ? $this->preloaded->value : $this->preloaded,
                    ),
                ),
            ),
            $this->splitStream->written(),
        );
    }

    public function php(PathOrigin $origin): PhpCalls
    {
        $pest = PhpCalls::inWith(...[
            ...$this->switched($this->patch, 'Pest::patched()', 'Pest::unpatched()'),
            ...$this->canary instanceof Group
                ? [sprintf('Pest::canary(%s)', PhpCalls::literal($this->canary->name()))]
                : [],
            ...$this->switched($this->bootTail, 'Pest::bootingTail()', 'Pest::tailUnbooted()'),
            ...$this->switched($this->workerOpcache, 'Pest::cachingInWorkers()', 'Pest::uncachedInWorkers()'),
            ...$this->switched($this->schemata, 'Pest::compilingSchemata()', 'Pest::withoutSchemata()'),
            ...$this->switched($this->bootMutants, 'Pest::bootingMutants()', 'Pest::mutantsUnbooted()'),
            ...$this->switched($this->bootChunks, 'Pest::bootingChunks()', 'Pest::chunksUnbooted()'),
            ...$this->narrowedKills instanceof NarrowedKillTrust ? [$this->narrowedKills->php()] : [],
            ...$this->longFilter instanceof LongFilter ? [$this->longFilter->php()] : [],
            ...$this->preloaded instanceof Preloaded ? [$this->preloaded->php()] : [],
        ]);

        return $pest->and($this->splitStream->php());
    }

    /**
     * The call that sets a switch as a layer sets it, on or off; none where the layer leaves it.
     *
     * @return list<string>
     */
    private function switched(bool|Absent $value, string $on, string $off): array
    {
        return match ($value) {
            true => [$on],
            false => [$off],
            default => [],
        };
    }
}
