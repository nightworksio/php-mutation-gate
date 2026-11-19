<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\Cost\JobTimes;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * What a shard result's `measured`, and each item of a ledger's `jobTimes`,
 * holds of a CI job's own time
 * (ADR-0196): `platform`, the digest of the platform its runner ran on,
 * `setup`, the seconds before its gate started, and `windDown`, the seconds
 * its gate went on for past its stop, and `passTwo`, how many times their
 * bound its second passes took; each left out where it measured none.
 *
 * @internal the shape of the job's fields of a shard result's `measured` and of a ledger's `jobTimes` items
 *
 * @phpstan-type Written array{platform?: string, setup?: float, windDown?: float, passTwo?: float}
 */
final readonly class JobTimesRecord
{
    public const string PLATFORM = 'platform';

    public const string SETUP = 'setup';

    public const string WIND_DOWN = 'windDown';

    public const string PASS_TWO = 'passTwo';

    /** @return Written */
    public static function of(JobTimes $job): array
    {
        $setup = $job->setup();
        $windDown = $job->windDown();
        $passTwo = $job->passTwo();

        return [
            ...$job->platform() === '' ? [] : [self::PLATFORM => $job->platform()],
            ...$setup instanceof Seconds ? [self::SETUP => $setup->seconds()] : [],
            ...$windDown instanceof Seconds ? [self::WIND_DOWN => $windDown->seconds()] : [],
            ...$passTwo instanceof NotGiven ? [] : [self::PASS_TWO => $passTwo],
        ];
    }

    /**
     * What a result's `measured` says its job measured of its own time; nothing where it says none.
     *
     * @throws NotInShape
     */
    public static function read(Node $measured): JobTimes
    {
        $platform = $measured->field(self::PLATFORM);
        $setup = $measured->field(self::SETUP);
        $windDown = $measured->field(self::WIND_DOWN);
        $passTwo = $measured->field(self::PASS_TWO);

        return JobTimes::of(
            $platform->isPresent() ? $platform->text() : '',
            $setup->isPresent() ? Seconds::of($setup->number()) : NotGiven::value(),
            $windDown->isPresent() ? Seconds::of($windDown->number()) : NotGiven::value(),
            $passTwo->isPresent() ? $passTwo->number() : NotGiven::value(),
        );
    }
}
