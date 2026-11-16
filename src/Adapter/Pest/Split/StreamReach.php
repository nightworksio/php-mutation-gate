<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_filter;
use function array_values;
use function getenv;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Core\Runner\PcovReach;

/**
 * The PHP options a split stream starts with where it records its tests'
 * lines with pcov (ADR-0135, ADR-0147): pcov collecting from every tree of
 * the project and from the schemata the stream serves in its files' place,
 * as a coverage run collects from every tree.
 *
 * @internal
 */
final readonly class StreamReach
{
    /**
     * What a run whose streams record with pcov tells Pest's own process.
     *
     * @return array<string, string>
     */
    public static function told(PcovReach $reach): array
    {
        $options = json_encode($reach->options(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return [GateVariable::SplitReach->value => $options];
    }

    /**
     * The options a stream starts with, as the gate told this process; none where it told none.
     *
     * @return list<string>
     */
    public static function options(): array
    {
        $told = getenv(GateVariable::SplitReach->value);
        $options = is_string($told) && $told !== '' ? json_decode($told, associative: true) : [];

        return is_array($options) ? array_values(array_filter($options, is_string(...))) : [];
    }
}
