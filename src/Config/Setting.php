<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

/** A value `Gate::with()` takes: the part of the config it sets, such as `Shards::seconds(600)`. */
interface Setting
{
    /** The part of the config this sets, as a JSON object from the top: `{"shards": {"seconds": 600}}`. */
    public function written(): string;
}
