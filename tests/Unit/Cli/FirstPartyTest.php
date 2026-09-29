<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\FirstParty;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Extension\Origin;

it('comes from this package', function (): void {
    expect(FirstParty::PACKAGE)->toBe('nightworksio/mutation-gate');
});

it('is named in this package\'s own composer.json', function (): void {
    $manifest = json_decode((string) file_get_contents(sprintf('%s/composer.json', dirname(__DIR__, 3))), associative: true);

    expect($manifest)->toMatchArray(['name' => FirstParty::PACKAGE, 'extra' => ['mutation-gate' => ['extensions' => [FirstParty::class]]]]);
});

it('registers nothing of its own, handing the registry back as it came', function (): void {
    $registry = new Extensions(Origin::of(FirstParty::PACKAGE));

    expect(new FirstParty()->extend($registry))->toBe($registry);
});
