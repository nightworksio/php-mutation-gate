<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Core\Coverage\MapSlices;
use NightWorksIO\MutationGate\Core\Coverage\MapVouch;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Proof\CacheRequest;
use NightWorksIO\MutationGate\Core\Proof\ProofCache;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\MapEngine;
use NightWorksIO\MutationGate\Tests\Fakes\MapEngineFake;

/** A map engine whose proof cache takes every request, keeping each pull and push it was asked for, and which vouches for no map. */
final class CachingEngine implements MapEngine, ProofCache
{
    /** @var list<CacheRequest> */
    public private(set) array $pulls = [];

    /** @var list<CacheRequest> */
    public private(set) array $pushes = [];

    public function vouches(Contents $text): MapVouch
    {
        return new MapEngineFake()->vouches($text);
    }

    public function split(Path $whole, MapSlices $slices): NotWritten
    {
        return new MapEngineFake()->split($whole, $slices);
    }

    public function pulled(CacheRequest $request): Written
    {
        $this->pulls[] = $request;

        return Written::to('the packs');
    }

    public function pushed(CacheRequest $request): Written
    {
        $this->pushes[] = $request;

        return Written::to('the store');
    }
}
