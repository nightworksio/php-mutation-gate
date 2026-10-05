<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Alert\Delivery as AlertDelivery;
use NightWorksIO\MutationGate\Adapter\Alert\Pause;
use NightWorksIO\MutationGate\Cli\Keyed\Deliver;
use NightWorksIO\MutationGate\Cli\Keyed\Installation;
use NightWorksIO\MutationGate\Cli\Keyed\LocatedStore;
use NightWorksIO\MutationGate\Cli\Keyed\Sending;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\BuiltinReporter;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Delivery\AlertPost;
use NightWorksIO\MutationGate\Core\Delivery\Delivery;
use NightWorksIO\MutationGate\Core\Delivery\DeliveryFile;
use NightWorksIO\MutationGate\Core\Delivery\LedgerPost;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\LedgerFile;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Port\ProofStore;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\StoppedClock;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * What deliver says, on its output and its errors, and its exit code, run in this project from this vendor.
 *
 * @param  list<string>                 $argv
 * @param  array<string, string>        $environment
 * @param  list<MockResponse>           $answers
 * @return array{string, string, int}
 */
function deliverRun(string $project, string $vendor, bool $throughComposer, array $argv, array $environment = [], array $answers = []): array
{
    $client = new MockHttpClient($answers);
    $clock = new StoppedClock('2026-10-05T12:00:00Z');
    $sending = new Sending($environment, new LocatedStore(Variables::of($environment), new Extensions(Origin::of('acme/gate'))->withProofStore(Name::of('s3'), static fn(): ProofStore => new ProofStoreFake())), $client, AlertDelivery::over($client, $clock, Pause::for(...)), $clock);
    $output = new BufferedOutput();
    $errors = new BufferedOutput();
    $code = new Deliver(Installation::of($project, $vendor, $throughComposer), $environment, $sending)->run(new ArgvInput($argv), $output, $errors);

    return [$output->fetch(), $errors->fetch(), $code];
}

const DELIVER_NOT_OWN = "deliver runs from the gate's own installation, never the project's, so it loads none of the project's code.\n";

it('is asked for by its name alone', function (): void {
    expect(Deliver::isAsked(new ArgvInput(['mutation-gate', 'deliver', '--from=x'])))->toBeTrue()
        ->and(Deliver::isAsked(new ArgvInput(['mutation-gate', 'verdict'])))->toBeFalse()
        ->and(Deliver::isAsked(new ArgvInput(['mutation-gate'])))->toBeFalse();
});

it('refuses to start through Composer\'s proxy, or from the project\'s own vendor', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'vendor/autoload.php', '<?php');
    $gate = Scratch::directory();

    expect(deliverRun($project, $gate, throughComposer: true, argv: ['mutation-gate', 'deliver']))->toBe(['', DELIVER_NOT_OWN, 2])
        ->and(deliverRun($project, sprintf('%s/vendor', $project), throughComposer: false, argv: ['mutation-gate', 'deliver']))->toBe(['', DELIVER_NOT_OWN, 2])
        ->and(deliverRun($project, sprintf('%s/./vendor/../vendor', $project), throughComposer: false, argv: ['mutation-gate', 'deliver']))->toBe(['', DELIVER_NOT_OWN, 2]);
});

it('says there is nothing to send where no delivery is', function (): void {
    $project = Scratch::directory();

    expect(deliverRun($project, Scratch::directory(), throughComposer: false, argv: ['mutation-gate', 'deliver', sprintf('--from=%s/nowhere', $project)]))
        ->toBe(['', sprintf("There is no delivery at %s/nowhere, so deliver sends nothing.\n", $project), 2]);
});

it('reads the delivery from .mutation-gate/delivery unless --from names another, and sends what it holds', function (): void {
    $project = Scratch::directory();
    $delivery = DeliveryFile::encode(Delivery::none()->withAlert(AlertPost::of(BuiltinReporter::Slack, '{}')));
    Scratch::write($project, 'elsewhere/delivery.json', $delivery);
    $posted = new MockResponse('ok');
    chdir($project);
    Scratch::write($project, '.mutation-gate/delivery/delivery.json', $delivery);

    expect(deliverRun($project, Scratch::directory(), throughComposer: false, argv: ['mutation-gate', 'deliver'], environment: ['MUTATION_GATE_SLACK_URL' => 'https://hooks.example/a'], answers: [$posted]))
        ->toBe(["Wrote Slack.\n", '', 0])
        ->and(deliverRun($project, Scratch::directory(), throughComposer: false, argv: ['mutation-gate', 'deliver', '--from=elsewhere']))
        ->toBe(["MUTATION_GATE_SLACK_URL is not set, so no alert goes to Slack.\n", '', 0]);
});

it('sends nothing from a delivery that names where to send', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'd/delivery.json', '{"format": 1, "comment": "x", "repository": "attacker/repo"}');

    expect(deliverRun($project, Scratch::directory(), throughComposer: false, argv: ['mutation-gate', 'deliver', sprintf('--from=%s/d', $project)]))
        ->toBe(['', "The delivery cannot be read, so deliver sends nothing: delivery.repository is not a key deliver takes from a delivery.\n", 2]);
});

it('refuses a command line that holds anything but its directory, saying what Symfony\'s console refuses', function (string $argument, string $named): void {
    [$output, $errors, $code] = deliverRun(Scratch::directory(), Scratch::directory(), throughComposer: false, argv: ['mutation-gate', 'deliver', $argument]);

    expect([$output, $code])->toBe(['', 2])
        ->and($errors)->toStartWith('deliver refuses its command line: ')
        ->and($errors)->toContain($named);
})->with([
    'another option' => ['--to=x', '"--to"'],
    'another argument' => ['x', '"x"'],
]);

it('writes the ledger beside the delivery only on a trusted run, to the store its own environment locates', function (): void {
    $project = Scratch::directory();
    $event = Scratch::directory();
    Scratch::write($event, 'event.json', '{"repository": {"default_branch": "main"}}');
    $ledger = Ledger::empty()->atBase(Digest::sha256Of('base'));
    Scratch::write($project, 'd/delivery.json', DeliveryFile::encode(Delivery::none()->withLedger(LedgerPost::to(Scope::branch('main')))));
    Scratch::write($project, 'd/ledger.json.gz', LedgerFile::encode($ledger));
    $store = new ProofStoreFake();
    $client = new MockHttpClient();
    $clock = new StoppedClock('2026-10-05T12:00:00Z');
    $environment = [
        'GITHUB_ACTIONS' => 'true',
        'GITHUB_EVENT_NAME' => 'push',
        'GITHUB_REF' => 'refs/heads/main',
        'GITHUB_EVENT_PATH' => sprintf('%s/event.json', $event),
        'MUTATION_GATE_STORE' => 's3',
        'MUTATION_GATE_STORE_BUCKET' => 'proofs',
        'AWS_ACCESS_KEY_ID' => 'id',
        'AWS_SECRET_ACCESS_KEY' => 'secret',
    ];
    $located = new LocatedStore(Variables::of($environment), new Extensions(Origin::of('acme/gate'))->withProofStore(Name::of('s3'), static fn(): ProofStore => $store));
    $sending = new Sending($environment, $located, $client, AlertDelivery::over($client, $clock, Pause::for(...)), $clock);
    $output = new BufferedOutput();
    $code = new Deliver(Installation::of($project, Scratch::directory(), throughComposer: false), $environment, $sending)
        ->run(new ArgvInput(['mutation-gate', 'deliver', sprintf('--from=%s/d', $project)]), $output, new BufferedOutput());

    expect($code)->toBe(0)
        ->and($store->read(Scope::branch('main')))->toEqual($ledger)
        ->and($output->fetch())->toBe("Wrote memory:refs/heads/main.\n");
});

it('fails a trusted run whose delivery holds a ledger it left no file for', function (): void {
    $project = Scratch::directory();
    $event = Scratch::directory();
    Scratch::write($event, 'event.json', '{"repository": {"default_branch": "main"}}');
    Scratch::write($project, 'd/delivery.json', DeliveryFile::encode(Delivery::none()->withLedger(LedgerPost::to(Scope::branch('main')))));
    $environment = [
        'GITHUB_ACTIONS' => 'true',
        'GITHUB_EVENT_NAME' => 'push',
        'GITHUB_REF' => 'refs/heads/main',
        'GITHUB_EVENT_PATH' => sprintf('%s/event.json', $event),
        'MUTATION_GATE_STORE' => 's3',
        'MUTATION_GATE_STORE_BUCKET' => 'proofs',
        'AWS_ACCESS_KEY_ID' => 'id',
        'AWS_SECRET_ACCESS_KEY' => 'secret',
    ];

    expect(deliverRun($project, Scratch::directory(), throughComposer: false, argv: ['mutation-gate', 'deliver', sprintf('--from=%s/d', $project)], environment: $environment))
        ->toBe(["The ledger is not written, since it cannot be read: ledger.json.gz is missing.\n", '', 2]);
});

it('starts in this process with the gate\'s own adapters, and refuses before anything else', function (): void {
    $project = Scratch::directory();
    $output = new BufferedOutput();
    $errors = new BufferedOutput();
    $code = Deliver::online(Installation::of($project, Scratch::directory(), throughComposer: true))
        ->run(new ArgvInput(['mutation-gate', 'deliver']), $output, $errors);

    expect([$output->fetch(), $errors->fetch(), $code])
        ->toBe(['', "deliver runs from the gate's own installation, never the project's, so it loads none of the project's code.\n", 2]);
});
