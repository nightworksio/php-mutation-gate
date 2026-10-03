<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/** The options the built-in object stores take under `proofs.store.with` (ADR-0007, ADR-0013, ADR-0028). */
enum StoreOption: string
{
    case Bucket = 'bucket';
    case Prefix = 'prefix';
    case Region = 'region';
    case Endpoint = 'endpoint';
    case PublicUrl = 'publicUrl';
    case Account = 'account';
    case Container = 'container';
    case PublicContainer = 'publicContainer';
}
