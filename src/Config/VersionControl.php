<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Config\BuiltinVersionControl;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

/**
 * `versionControl`: where a run learns what changed and what the repository holds (ADR-0005); git's, or GitHub's
 * under GitHub Actions, where the config names none.
 */
final readonly class VersionControl implements Setting
{
    private function __construct(private Json|string $choice)
    {
    }

    /** Git's: the changes since the base, and the repository's commits and branches. */
    public static function git(): self
    {
        return self::uses(BuiltinVersionControl::Git->value);
    }

    /** Git's, with GitHub's word on what the default branch already proved. */
    public static function github(): self
    {
        return self::uses(BuiltinVersionControl::GitHub->value);
    }

    /** A change source and repository another extension registers by name, or a class, with its options. */
    public static function uses(string $versionControl, Option ...$options): self
    {
        return new self(Option::choice($versionControl, ...$options));
    }

    public function written(): Json
    {
        return Json::object(Member::of('versionControl', $this->choice));
    }
}
