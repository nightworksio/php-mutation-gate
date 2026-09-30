<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Doctor\DoctorJson;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Troubleshooting\Guide;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;
use NightWorksIO\MutationGate\Tests\Support\Schema;

$findings = static fn(): Findings => Findings::of(
    Finding::of(Slug::XdebugSlowsTests, Severity::Slow, 'Xdebug runs in develop.', 'It slows tests.', 'Set XDEBUG_MODE=coverage.')
        ->costing(Seconds::of(120.0)),
    Finding::of(Slug::NoTree, Severity::WillFail, 'No tree.', 'Nothing to mutate.', 'Name one.'),
)->and();

it('writes each finding, its link into the release\'s guide and whether any fails a run', function () use ($findings): void {
    expect(json_decode(DoctorJson::of($findings(), Guide::ofInstalled('1.2.0')), associative: true))->toBe([
        'format' => 1,
        'failsARun' => true,
        'findings' => [
            [
                'slug' => 'no-tree',
                'severity' => 'will-fail',
                'found' => 'No tree.',
                'why' => 'Nothing to mutate.',
                'fix' => 'Name one.',
                'link' => 'https://github.com/nightworksio/php-mutation-gate/blob/v1.2.0/.docs/guide/troubleshooting.md#no-tree',
            ],
            [
                'slug' => 'xdebug-slows-tests',
                'severity' => 'slow',
                'found' => 'Xdebug runs in develop.',
                'why' => 'It slows tests.',
                'fix' => 'Set XDEBUG_MODE=coverage.',
                'seconds' => 120.0,
                'link' => 'https://github.com/nightworksio/php-mutation-gate/blob/v1.2.0/.docs/guide/troubleshooting.md#xdebug-slows-tests',
            ],
        ],
    ]);
});

it('writes what its schema describes, and an empty list where nothing was found', function () use ($findings): void {
    expect(Schema::errors(DoctorJson::of($findings(), Guide::unreleased()), Schema::at('resources/doctor.schema.json')))->toBe([])
        ->and(json_decode(DoctorJson::of(Findings::none(), Guide::unreleased()), associative: true))
        ->toBe(['format' => 1, 'failsARun' => false, 'findings' => []]);
});
