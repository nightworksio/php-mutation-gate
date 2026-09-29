<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Port;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Tree\Trees;

/** Where the trees come from: `phpunit.xml`'s `<source>`, Composer's autoload, monorepo manifests. */
interface TreeSource
{
    /** Every tree, with the floor each declares and the package each belongs to. */
    public function trees(): Trees|CannotJudge;
}
