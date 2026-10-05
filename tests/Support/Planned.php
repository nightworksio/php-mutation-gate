<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Flow\Handoff;
use NightWorksIO\MutationGate\Cli\Flow\PlanMade;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;

/**
 * Plans over the flows' project, made on its head for a push to `main`: the
 * money file in shard 1, the held path in shard 2, each unit keyed.
 */
final readonly class Planned
{
    public const string BASE = 'base of every key';

    public static function money(): Unit
    {
        return Unit::file(Path::of('src/Money.php'));
    }

    public static function held(): Unit
    {
        return Unit::held(Path::of('src/Held.php'), Group::named('holds:src/Held.php'));
    }

    /** The money file in shard 1 and the held path in shard 2. */
    public static function twoShards(): Plan
    {
        return self::of(
            Shard::of(ShardId::of(1), Package::at(Path::root()), Units::of(self::money()), Seconds::of(2.0), 'money'),
            Shard::of(ShardId::of(2), Package::at(Path::root()), Units::of(self::held()), Seconds::of(1.0), 'held'),
        );
    }

    /** Both units in one shard: the held path runs first, then the money file. */
    public static function oneShard(): Plan
    {
        return self::of(Shard::of(
            ShardId::of(1),
            Package::at(Path::root()),
            Units::of(self::money(), self::held()),
            Seconds::of(3.0),
            'everything',
        ));
    }

    /** A plan whose shards were handed the fixture runner's map in a project, as `plan` hands them. */
    public static function handedIn(string $project, Plan $plan): Plan
    {
        new Handoff(Directory::at($project))->write($plan, Flows::map(), KillHistory::none(), Unplaced::map());

        return $plan;
    }

    public static function of(Shard ...$shards): Plan
    {
        return Plan::of(Revision::ref(Flows::HEAD), Digest::sha256Of(self::BASE), self::keys(), Shards::of(...$shards))
            ->on(RunOn::at(Scope::branch('main'), Scope::branch('main')))
            ->naming(TestNames::none());
    }

    /** The plan a planning made, or why it made none. */
    public static function from(PlanMade|CannotJudge $made): Plan|CannotJudge
    {
        return $made instanceof PlanMade ? $made->plan() : $made;
    }

    /** The key each unit has. */
    private static function keys(): Keys
    {
        return Keys::none()
            ->with(Path::of('src/Money.php'), Digest::sha256Of('money'))
            ->with(Path::of('src/Held.php'), Digest::sha256Of('held'));
    }
}
