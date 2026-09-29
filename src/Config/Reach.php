<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use function array_values;

use NightWorksIO\MutationGate\Core\Config\Definition\Json;

/** What decides what a change reaches (ADR-0005): `packages`, `reach.everything`, `holds.hotPath`. */
final readonly class Reach implements Setting
{
    private function __construct(private string $json)
    {
    }

    /** `packages`: the globs of a monorepo's packages. */
    public static function packages(string ...$globs): self
    {
        return new self(Json::encode(['packages' => array_values($globs)]));
    }

    /** `reach.everything`: the globs of the files that reach everything. */
    public static function everything(string ...$globs): self
    {
        return new self(Json::encode(['reach' => ['everything' => array_values($globs)]]));
    }

    /** `holds.hotPath`: the share of the suite past which code nothing holds is warned about. */
    public static function hotPath(int|float $share): self
    {
        return new self(Json::encode(['holds' => ['hotPath' => $share]]));
    }

    public function written(): string
    {
        return $this->json;
    }
}
