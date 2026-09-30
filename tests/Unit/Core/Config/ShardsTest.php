<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Badge;
use NightWorksIO\MutationGate\Core\Config\Ci;
use NightWorksIO\MutationGate\Core\Config\Floors;
use NightWorksIO\MutationGate\Core\Config\Ignores;
use NightWorksIO\MutationGate\Core\Config\Local;
use NightWorksIO\MutationGate\Core\Config\Part;
use NightWorksIO\MutationGate\Core\Config\Pest;
use NightWorksIO\MutationGate\Core\Config\Proofs;
use NightWorksIO\MutationGate\Core\Config\Reach;
use NightWorksIO\MutationGate\Core\Config\Reports;
use NightWorksIO\MutationGate\Core\Config\Setup;
use NightWorksIO\MutationGate\Core\Config\Shards;
use NightWorksIO\MutationGate\Core\Config\Triage;

it('stays as it is under a part of another kind', function (Part $part): void {
    expect(Shards::standard()->over($part))->toEqual(Shards::standard())
        ->and($part->over(Shards::standard()))->toEqual($part);
})->with([
    'setup' => [Setup::standard()],
    'floors' => [Floors::standard()],
    'reach' => [Reach::standard()],
    'ci' => [Ci::standard()],
    'proofs' => [Proofs::standard()],
    'triage' => [Triage::standard()],
    'ignores' => [Ignores::standard()],
    'reports' => [Reports::standard()],
    'badge' => [Badge::standard()],
    'pest' => [Pest::standard()],
    'local' => [Local::standard()],
]);
