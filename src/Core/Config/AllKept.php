<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/** What `proofs.keep.killers` says where nothing but the byte limits caps what a ledger keeps. */
enum AllKept: string
{
    case All = 'all';
}
