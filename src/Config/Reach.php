<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Config\ChangedTests;
use NightWorksIO\MutationGate\Core\Config\FailFast;
use NightWorksIO\MutationGate\Core\Config\LockMoveReach;
use NightWorksIO\MutationGate\Core\Format\Json;

/**
 * What decides what a change reaches (ADR-0005): `packages`, `reach.everything`, `reach.lockPackages`,
 * `reach.changedTests`, `reach.fixtures`, `holds.hotPath`, `run.full`, `run.failFast`, `run.failFastWaits`.
 */
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

    /**
     * `reach.lockPackages`: the packages, by name or a pattern of names such as `pestphp/*`, whose move in a
     * `composer.lock` decides how the gate runs (ADR-0030).
     */
    public static function lockPackages(string ...$patterns): self
    {
        return new self(Json::at('reach.lockPackages', Json::items(...$patterns)));
    }

    /** `reach.lockMoves: everything`: a lock's move reaches every unit, the default. */
    public static function lockMovesReachEverything(): self
    {
        return new self(Json::at('reach.lockMoves', LockMoveReach::Everything->value));
    }

    /** `reach.lockMoves: named`: a lock's move reaches every unit only where it moved what `lockPackages` names. */
    public static function lockMovesReachByName(): self
    {
        return new self(Json::at('reach.lockMoves', LockMoveReach::Named->value));
    }

    /**
     * `reach.lockMoves: loaded`: a lock's move reaches the units run by the tests that loaded a moved package. A
     * package loaded only in a worker no test started is not seen.
     */
    public static function lockMovesReachWhatLoadedThem(): self
    {
        return new self(Json::at('reach.lockMoves', LockMoveReach::Loaded->value));
    }

    /**
     * `reach.changedTests`, `narrow`: a changed test file reaches the units its changed tests run, or all its tests
     * where a change moved code they share (ADR-0065).
     */
    public static function narrowChangedTests(): self
    {
        return new self(Json::at('reach.changedTests', ChangedTests::Narrow->value));
    }

    /** `reach.changedTests`, `file`: a changed test file reaches the units all its tests run, whatever changed. */
    public static function wholeChangedTestFiles(): self
    {
        return new self(Json::at('reach.changedTests', ChangedTests::File->value));
    }

    /**
     * `reach.fixtures`: the globs of the fixture projects, the directories whose files reach only the tests that name
     * their path (ADR-0064).
     */
    public static function fixtures(string ...$globs): self
    {
        return new self(Json::at('reach.fixtures', Json::items(...$globs)));
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

    /** `run.failFast`, `off`: a pull request's shard passes once it cannot pass, and the verdict alone fails. */
    public static function failFastOff(): self
    {
        return self::failingFast(FailFast::Off);
    }

    /** `run.failFast`, `mark`: a pull request's shard judges every unit once it cannot pass, and then fails. */
    public static function failFastMarking(): self
    {
        return self::failingFast(FailFast::Mark);
    }

    /** `run.failFast`, `stop`: a pull request's shard stops once it cannot pass, its other units unjudged. */
    public static function failFastStopping(): self
    {
        return self::failingFast(FailFast::Stop);
    }

    /** `run.failFast`, `cancel`: a pull request's shard stops once it cannot pass, and the CI cancels the others. */
    public static function failFastCancelling(): self
    {
        return self::failingFast(FailFast::Cancel);
    }

    /**
     * `run.failFastWaits`: why static analysis may leave a survivor unchecked that a pull request's shard waits on
     * rather than counting as a doom, each an outcome its warnings name, `out-of-time` and `failed` by default.
     */
    public static function failFastWaitingOn(string ...$outcomes): self
    {
        return new self(Json::at('run.failFastWaits', Json::items(...$outcomes)));
    }

    public function written(): Json
    {
        return $this->json;
    }

    private static function failingFast(FailFast $failFast): self
    {
        return new self(Json::at('run.failFast', $failFast->value));
    }
}
