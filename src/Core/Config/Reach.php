<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/** What decides what a change reaches (ADR-0005): `packages`, `reach.everything` and `holds.hotPath`. */
final readonly class Reach
{
    /**
     * @param Listed<string> $packages
     * @param Listed<string> $everything
     */
    public function __construct(private Listed $packages, private Listed $everything, private float $hotPath)
    {
    }

    /** @return Listed<string> the globs of the packages in a monorepo */
    public function packages(): Listed
    {
        return $this->packages;
    }

    /** @return Listed<string> the globs of the files that reach everything */
    public function everything(): Listed
    {
        return $this->everything;
    }

    /** The share of the suite past which code nothing holds is warned about. */
    public function hotPath(): float
    {
        return $this->hotPath;
    }
}
