<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function array_map;
use function implode;

/**
 * The Infection releases whose two places `infection:patch` rewrites were
 * checked against, each from its tag: every 0.35 release ships both as the
 * hunks expect. The runner contracts run the lowest and the highest.
 */
enum Release: string
{
    case V0_35_0 = '0.35.0';
    case V0_35_1 = '0.35.1';
    case V0_35_2 = '0.35.2';
    case V0_35_3 = '0.35.3';
    case V0_35_4 = '0.35.4';
    case V0_35_5 = '0.35.5';
    case V0_35_6 = '0.35.6';

    /** Every release the patch supports, as a person reads the list. */
    public static function listed(): string
    {
        return implode(', ', array_map(static fn(self $release): string => $release->value, self::cases()));
    }
}
