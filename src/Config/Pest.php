<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

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

    /** `pest.bootTail`: the verification tail's workers boot nothing of the project, as mutants' do. */
    public static function tailUnbooted(): self
    {
        return new self(Json::at('pest.bootTail', value: false));
    }

    /** `pest.canary`: the group of tests that shows the patches work. */
    public static function canary(string $group): self
    {
        return new self(Json::at('pest.canary', $group));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
