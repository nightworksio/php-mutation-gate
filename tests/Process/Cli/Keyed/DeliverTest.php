<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Alert\Delivery as AlertDelivery;
use NightWorksIO\MutationGate\Adapter\Alert\Pause;
use NightWorksIO\MutationGate\Adapter\Outbreak\CacheDelivery;
use NightWorksIO\MutationGate\Cli\Keyed\DefaultBranch;
use NightWorksIO\MutationGate\Cli\Keyed\Deliver;
use NightWorksIO\MutationGate\Cli\Keyed\Installation;
use NightWorksIO\MutationGate\Cli\Keyed\LocatedStore;
use NightWorksIO\MutationGate\Cli\Keyed\Sending;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Delivery\Delivery;
use NightWorksIO\MutationGate\Core\Delivery\DeliveryFile;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Proof\CacheClass;
use NightWorksIO\MutationGate\Core\Proof\CachePack;
use NightWorksIO\MutationGate\Core\Proof\CacheRequest;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Tests\Support\OutbreakStandIn;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\StoppedClock;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpClient\MockHttpClient;

$holds = [
    'holds:src/Adapter/Outbreak/CacheDelivery.php',
    'holds:src/Cli/Keyed/Deliver.php',
];

afterEach(function (): void {
    Scratch::sweep();
});

it('pushes the proof pack the delivery holds into the workflow\'s registry, by the binary the package ships', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'd/delivery.json', DeliveryFile::encode(Delivery::none()));
    Scratch::write($project, sprintf('d/%s', CacheRequest::DELIVERED), CacheDelivery::request('ghcr.io/owner/proofs', Digest::sha256Of('base'), CacheClass::Branch)->text());
    Scratch::write($project, sprintf('d/%s', CachePack::DELIVERED), 'entries');
    $environment = [CacheDelivery::REGISTRY => 'ghcr.io/owner/proofs', CacheDelivery::TOKEN => 'secret'];
    $client = new MockHttpClient();
    $clock = new StoppedClock('2026-10-05T12:00:00Z');
    $sending = new Sending($environment, new LocatedStore(Variables::of($environment), Extensions::of(Origin::of('acme/gate'))), $client, AlertDelivery::over($client, $clock, Pause::for(...)), $clock);
    $binary = OutbreakStandIn::shipped('{"outbreak":1,"pushed":1}');
    $output = new BufferedOutput();

    $code = new Deliver(Installation::of($project, Scratch::directory(), throughComposer: false), $environment, $sending, $binary->shippedIn())
        ->run(new ArgvInput(['mutation-gate', 'deliver', sprintf('--from=%s/d', $project)]), $output, new BufferedOutput());

    expect([$output->fetch(), $code])->toBe(["Wrote ghcr.io/owner/proofs. Outbreak pushed 1 of the proof cache's packs.\n", 0])
        ->and($binary->arguments())->toStartWith('cache push')
        ->and($binary->read())->toContain('ghcr.io/owner/proofs');
})->group(...$holds);

it('pushes the trusted pack only where the run writes the default branch\'s ledger, and says why it does not elsewhere', function (string $ref, string $said): void {
    $project = Scratch::directory();
    Scratch::write($project, 'd/delivery.json', DeliveryFile::encode(Delivery::none()));
    Scratch::write($project, sprintf('d/%s', CacheRequest::DELIVERED), CacheDelivery::request('ghcr.io/owner/proofs', Digest::sha256Of('base'), CacheClass::Trusted)->text());
    Scratch::write($project, sprintf('d/%s', CachePack::DELIVERED), 'entries');
    $environment = [
        CacheDelivery::REGISTRY => 'ghcr.io/owner/proofs',
        CacheDelivery::TOKEN => 'secret',
        'GITHUB_ACTIONS' => 'true',
        'GITHUB_EVENT_NAME' => 'push',
        'GITHUB_REF' => $ref,
        DefaultBranch::VARIABLE => 'main',
    ];
    $client = new MockHttpClient();
    $clock = new StoppedClock('2026-10-05T12:00:00Z');
    $sending = new Sending($environment, new LocatedStore(Variables::of($environment), Extensions::of(Origin::of('acme/gate'))), $client, AlertDelivery::over($client, $clock, Pause::for(...)), $clock);
    $output = new BufferedOutput();

    new Deliver(Installation::of($project, Scratch::directory(), throughComposer: false), $environment, $sending, OutbreakStandIn::shipped('{"outbreak":1,"pushed":1}')->shippedIn())
        ->run(new ArgvInput(['mutation-gate', 'deliver', sprintf('--from=%s/d', $project)]), $output, new BufferedOutput());

    expect($output->fetch())->toContain($said);
})->with([
    'a push of the default branch' => ['refs/heads/main', 'Outbreak pushed 1 of the proof cache\'s packs.'],
    'a push of another branch' => ['refs/heads/feature', 'This run writes no default branch ledger, so deliver pushes no trusted pack.'],
])->group(...$holds);
