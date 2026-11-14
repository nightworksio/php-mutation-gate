<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Outbreak\CacheDelivery;
use NightWorksIO\MutationGate\Cli\Keyed\Fetch;
use NightWorksIO\MutationGate\Cli\Keyed\Installation;
use NightWorksIO\MutationGate\Cli\Keyed\LocatedStore;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Port\ProofStore;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Support\OutbreakStandIn;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;

afterEach(function (): void {
    Scratch::sweep();
});

it('pulls each class\'s newest proof pack into the directory it fetches into, by the binary the package ships, where the workflow names a registry', function (): void {
    $project = Scratch::directory();
    $environment = Variables::of([
        'MUTATION_GATE_STORE' => 's3',
        'MUTATION_GATE_STORE_BUCKET' => 'proofs',
        'MUTATION_GATE_DEFAULT_BRANCH' => 'main',
        'AWS_ACCESS_KEY_ID' => 'id',
        'AWS_SECRET_ACCESS_KEY' => 'secret',
        CacheDelivery::REGISTRY => 'ghcr.io/owner/proofs',
    ]);
    $store = new ProofStoreFake()->keeping(Scope::branch('main'), Ledger::empty()->atBase(Digest::sha256Of('main')));
    $located = new LocatedStore($environment, Extensions::of(Origin::of('acme/gate'))->withProofStore(Name::of('s3'), static fn(): ProofStore => $store));
    $binary = OutbreakStandIn::shipped('{"outbreak":1,"pulled":2}');
    $output = new BufferedOutput();
    chdir($project);

    $code = new Fetch(Installation::of($project, Scratch::directory(), throughComposer: false), $environment, $located, $binary->shippedIn())
        ->run(new ArgvInput(['mutation-gate', 'fetch']), $output, new BufferedOutput());

    expect($code)->toBe(0)
        ->and($output->fetch())->toEndWith("\nWrote .mutation-gate/fetched. Outbreak pulled 2 of the proof cache's packs.\n")
        ->and($binary->arguments())->toStartWith('cache pull')
        ->and($binary->read())->toContain('ghcr.io/owner/proofs');
});
