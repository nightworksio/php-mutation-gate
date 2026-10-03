<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Http;

use NightWorksIO\MutationGate\Core\CannotJudge;

/** Where a store gets the token its requests carry, or learns why there is none. */
interface Tokens
{
    public function token(): Token|CannotJudge;
}
