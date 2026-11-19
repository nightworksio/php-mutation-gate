<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;

/**
 * How Pest's warm workers boot, compile and serve each run (ADR-0075, ADR-0088,
 * ADR-0091): keys of `pest`.
 */
final readonly class PestWorkers implements Setting
{
    private function __construct(private Json $json)
    {
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

    /** `pest.bootChunks`: a shard's chunks keep their mutants' workers, where those boot and cache nothing. */
    public static function bootingChunks(): self
    {
        return new self(Json::at('pest.bootChunks', value: true));
    }

    /** `pest.bootChunks` off, as by default: each chunk starts its mutants' workers afresh. */
    public static function chunksUnbooted(): self
    {
        return new self(Json::at('pest.bootChunks', value: false));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
