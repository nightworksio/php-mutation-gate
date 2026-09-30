<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Format\Json;

/**
 * One part of a layer of config, such as the floors or the shards: the
 * settings a layer writes there, each of which a later layer may replace,
 * and the value each takes when every layer leaves it out.
 */
interface Part
{
    /** A part that sets nothing. */
    public static function none(): self;

    /** A part that sets every setting to the value it takes when every layer leaves it out. */
    public static function standard(): self;

    /** This part with a later layer's laid over it: what the later one sets wins, and a list grows, but for `trees`. */
    public function over(self $later): self;

    /** What this part sets, as a config written at this origin writes it. */
    public function written(Origin $origin): Json;

    /** What this part sets, as the PHP builder's calls. */
    public function php(Origin $origin): PhpCalls;
}
