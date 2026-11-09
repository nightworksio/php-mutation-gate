<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Config\LongFilter;
use NightWorksIO\MutationGate\Core\Config\NarrowedKillTrust;
use NightWorksIO\MutationGate\Core\Format\Json;

/** The Pest runner's own settings (ADR-0004): `pest`. */
final readonly class Pest implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /** The optional Pest patches are applied. */
    public static function patched(): self
    {
        return new self(Json::at('pest.patch', value: true));
    }

    /** The optional Pest patches are not applied. */
    public static function unpatched(): self
    {
        return new self(Json::at('pest.patch', value: false));
    }

    /** `pest.bootTail`: the verification tail's workers boot the project before they fork (ADR-0075). */
    public static function bootingTail(): self
    {
        return new self(Json::at('pest.bootTail', value: true));
    }

    /** `pest.workerOpcache`, as by default: Pest's warm workers compile through opcache (ADR-0088). */
    public static function cachingInWorkers(): self
    {
        return new self(Json::at('pest.workerOpcache', value: true));
    }

    /** `pest.workerOpcache` off: Pest's warm workers run with opcache off, every run compiling afresh. */
    public static function uncachedInWorkers(): self
    {
        return new self(Json::at('pest.workerOpcache', value: false));
    }

    /** `pest.schemata`, as by default: each file's mutants are compiled into one schema (ADR-0091). */
    public static function compilingSchemata(): self
    {
        return new self(Json::at('pest.schemata', value: true));
    }

    /** `pest.schemata` off: each mutant's own run is served a mutated copy of its own. */
    public static function withoutSchemata(): self
    {
        return new self(Json::at('pest.schemata', value: false));
    }

    /**
     * `pest.bootMutants`, as by default: the workers of mutants' own runs boot
     * the project, no test file loaded, before they fork (ADR-0091).
     */
    public static function bootingMutants(): self
    {
        return new self(Json::at('pest.bootMutants', value: true));
    }

    /** `pest.bootMutants` off: each child of a mutants' worker boots the project as a fresh run does. */
    public static function mutantsUnbooted(): self
    {
        return new self(Json::at('pest.bootMutants', value: false));
    }

    /** `pest.bootTail`: the verification tail's workers boot nothing of the project. */
    public static function tailUnbooted(): self
    {
        return new self(Json::at('pest.bootTail', value: false));
    }

    /** `pest.canary`: the group of tests that shows the patches work. */
    public static function canary(string $group): self
    {
        return new self(Json::at('pest.canary', $group));
    }

    /** `pest.trials`, `true`: a mutant left uncovered on a line that is not executable is tried, as by default. */
    public static function trying(): self
    {
        return new self(Json::at('pest.trials', value: true));
    }

    /** `pest.trials`, `false`: such a mutant is left uncovered, which loosens under `uncovered: exclude` (ADR-0142). */
    public static function withoutTrials(): self
    {
        return new self(Json::at('pest.trials', value: false));
    }

    /** `pest.valueReaders`, `true`: a declared value's lines are covered by the tests that read it (ADR-0037), as by default. */
    public static function readingValues(): self
    {
        return new self(Json::at('pest.valueReaders', value: true));
    }

    /** `pest.valueReaders`, `false`: a declared value's mutants are tried instead. */
    public static function withoutValueReaders(): self
    {
        return new self(Json::at('pest.valueReaders', value: false));
    }

    /** `pest.narrow`, `true`: a patched mutant's own run loads only the test files its covering tests need, as by default. */
    public static function narrowing(): self
    {
        return new self(Json::at('pest.narrow', value: true));
    }

    /** `pest.narrow`, `false`: every mutant's own run loads every test file, and no narrowed kill needs vouching for. */
    public static function unnarrowed(): self
    {
        return new self(Json::at('pest.narrow', value: false));
    }

    /** `pest.narrowedKills`, `vouch`: a narrowed kill counts once its replay, alone run or run again vouches for it, as by default. */
    public static function vouchingForNarrowedKills(): self
    {
        return new self(Json::at('pest.narrowedKills', NarrowedKillTrust::Vouch->value));
    }

    /** `pest.narrowedKills`, `trust`: a narrowed kill counts as the narrowed run found it, which loosens (ADR-0142). */
    public static function trustingNarrowedKills(): self
    {
        return new self(Json::at('pest.narrowedKills', NarrowedKillTrust::Trust->value));
    }

    /** `pest.longFilter`, `files`: a run whose filter cannot be passed runs every test of the files it loaded. */
    public static function longFilterByFiles(): self
    {
        return new self(Json::at('pest.longFilter', LongFilter::Files->value));
    }

    /** `pest.longFilter`, `set`: such a run selects exactly its covering tests, named in a file (ADR-0138). */
    public static function longFilterBySet(): self
    {
        return new self(Json::at('pest.longFilter', LongFilter::Set->value));
    }

    /** `pest.longFilter`, `until-killer`: its control runs the run's own order up to its last killer (ADR-0138). */
    public static function longFilterUntilKiller(): self
    {
        return new self(Json::at('pest.longFilter', LongFilter::UntilKiller->value));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
