<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Plugin;
use NightWorksIO\MutationGate\Adapter\PhpUnit\IssueKillers;
use NightWorksIO\MutationGate\Cli\PestPlugin;
use NightWorksIO\MutationGate\Core\Test\RunIssues;
use Pest\Contracts\Plugins\Bootable;
use Pest\Contracts\Plugins\HandlesArguments;

it('is the Pest plugin Pest boots and hands arguments, handling them as the Pest adapter\'s plugin does', function (): void {
    $arguments = ['vendor/bin/pest', '--filter=adds'];

    expect(new PestPlugin())->toBeInstanceOf(Bootable::class)
        ->and(new PestPlugin())->toBeInstanceOf(HandlesArguments::class)
        ->and(new PestPlugin()->handleArguments($arguments))
        ->toBe(new Plugin(static fn(): RunIssues => new IssueKillers())->handleArguments($arguments));
});
