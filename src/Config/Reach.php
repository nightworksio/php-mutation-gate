<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;

/** What decides what a change reaches (ADR-0005): `packages`, `reach.everything`, `holds.hotPath`, `run.full`. */
final readonly class Reach implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /** `packages`: the globs of a monorepo's packages. */
    public static function packages(string ...$globs): self
    {
        return new self(Json::at('packages', Json::items(...$globs)));
    }

    /** `reach.everything`: the globs of the files that reach everything. */
    public static function everything(string ...$globs): self
    {
        return new self(Json::at('reach.everything', Json::items(...$globs)));
    }

    /** `holds.hotPath`: the share of the suite past which code nothing holds is warned about. */
    public static function hotPath(int|float $share): self
    {
        return new self(Json::at('holds.hotPath', $share));
    }

    /** `run.full`, `true`: a run given neither `--full` nor `--changed-since` considers every unit. */
    public static function fullByDefault(): self
    {
        return new self(Json::at('run.full', value: true));
    }

    /** `run.full`, `false`: a run given neither `--full` nor `--changed-since` reads its change since `last-passed`. */
    public static function changedByDefault(): self
    {
        return new self(Json::at('run.full', value: false));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
