<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Alert\Delivery as AlertDelivery;
use NightWorksIO\MutationGate\Adapter\Alert\Pause;
use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Cli\Keyed\LocatedStore;
use NightWorksIO\MutationGate\Cli\Keyed\Sending;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\BuiltinReporter;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Delivery\AlertPost;
use NightWorksIO\MutationGate\Core\Delivery\Delivery;
use NightWorksIO\MutationGate\Core\Delivery\LedgerPost;
use NightWorksIO\MutationGate\Core\Delivery\OtlpPost;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Format\TooLarge;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Port\ProofStore;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\StoppedClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * `deliver` in this environment, with one store registered as `s3`, counting each time it is built, and posting to
 * these answers, each kept as it was asked.
 *
 * @param array<string, string>   $environment
 * @param ArrayObject<int, string> $built
 * @param list<MockResponse>      $answers
 */
function sendingOf(array $environment, ArrayObject $built, ProofStoreFake $store, array $answers = []): Sending
{
    $build = static function (Options $options) use ($built, $store): ProofStore {
        $built->append($options->written()->line());

        return $store;
    };
    $extensions = new Extensions(Origin::of('acme/gate'))
        ->withProofStore(Name::of('s3'), $build)
        ->withProofStore(Name::of('gcs'), $build);
    $client = new MockHttpClient($answers);
    $clock = new StoppedClock('2026-10-05T12:00:00Z');

    return new Sending($environment, new LocatedStore(Variables::of($environment), $extensions), $client, AlertDelivery::over($client, $clock, Pause::for(...)), $clock);
}

/** A delivery that holds a ledger for this scope. */
function sendingLedger(Scope $scope): Delivery
{
    return Delivery::none()->withLedger(LedgerPost::to($scope));
}

/** Where deliver's own environment says the store is, an S3 bucket, and the keys it holds for it. */
const SENDING_STORE = [
    'MUTATION_GATE_STORE' => 's3',
    'MUTATION_GATE_STORE_BUCKET' => 'proofs',
    'AWS_ACCESS_KEY_ID' => 'id',
    'AWS_SECRET_ACCESS_KEY' => 'secret',
];

/**
 * The headers a mock answer was asked with, one to a line.
 */
function sendingHeaders(MockResponse $answer): string
{
    $headers = $answer->getRequestOptions()['headers'];

    return is_array($headers) ? implode("\n", array_filter($headers, is_string(...))) : '';
}

it('builds no store, reads no ledger and fails nothing on a run that is no trusted run of the default branch', function (): void {
    $built = new ArrayObject();
    $read = new ArrayObject();
    $store = new ProofStoreFake();
    $sent = sendingOf(SENDING_STORE, $built, $store)->sent(
        sendingLedger(Scope::branch('main')),
        NotWritten::because('This run is no push, schedule or manual run of the default branch, so deliver writes no ledger.'),
        static function () use ($read): Ledger {
            $read->append('read');

            return Ledger::empty();
        },
    );

    expect([...$built])->toBe([])
        ->and([...$read])->toBe([])
        ->and($store->read(Scope::branch('main')))->toEqual(Ledger::empty())
        ->and($sent->said())->toBe(['This run is no push, schedule or manual run of the default branch, so deliver writes no ledger.'])
        ->and($sent->exit())->toBe(ExitCode::Passed);
});

it('writes no ledger for a scope other than the one its own run may write', function (): void {
    $built = new ArrayObject();
    $read = new ArrayObject();
    $sent = sendingOf(SENDING_STORE, $built, new ProofStoreFake())->sent(
        sendingLedger(Scope::pullRequest(7)),
        Scope::branch('main'),
        static function () use ($read): Ledger {
            $read->append('read');

            return Ledger::empty();
        },
    );

    expect([...$built])->toBe([])
        ->and([...$read])->toBe([])
        ->and($sent->said())->toBe(['The ledger is not written: the delivery holds refs/pull/7\'s, and this run writes refs/heads/main\'s alone.'])
        ->and($sent->exit())->toBe(ExitCode::CannotJudge);
});

it('writes the ledger to the store its own environment locates, under its own run\'s scope, on a trusted run', function (): void {
    $built = new ArrayObject();
    $store = new ProofStoreFake();
    $ledger = Ledger::empty()->withPassed(Passed::of(
        Revision::ref('abc'),
        'mutation / verdict',
        0,
    ));
    $sent = sendingOf(SENDING_STORE, $built, $store)->sent(sendingLedger(Scope::branch('main')), Scope::branch('main'), static fn(): Ledger => $ledger);

    expect([...$built])->toBe(['{"prefix":"mutation-gate","region":"us-east-1","insecureEndpoint":false,"bucket":"proofs"}'])
        ->and($store->read(Scope::branch('main')))->toEqual($ledger)
        ->and($sent->exit())->toBe(ExitCode::Passed);
});

it('takes every part of the store\'s location from its own environment', function (): void {
    $built = new ArrayObject();
    $environment = [
        ...SENDING_STORE,
        'MUTATION_GATE_STORE_PREFIX' => 'ci/proofs',
        'MUTATION_GATE_STORE_REGION' => 'auto',
        'MUTATION_GATE_STORE_ENDPOINT' => 'https://r2.example.com',
    ];
    sendingOf($environment, $built, new ProofStoreFake())
        ->sent(sendingLedger(Scope::branch('main')), Scope::branch('main'), static fn(): Ledger => Ledger::empty());

    expect([...$built])
        ->toBe(['{"prefix":"ci/proofs","region":"auto","insecureEndpoint":false,"bucket":"proofs","endpoint":"https://r2.example.com"}']);
});

it('writes no ledger, and fails, where its own environment names no store that needs credentials, or one its options refuse', function (string $store, string $bucket, string $key, string $why): void {
    $built = new ArrayObject();
    $environment = array_filter(
        ['MUTATION_GATE_STORE' => $store, 'MUTATION_GATE_STORE_BUCKET' => $bucket, 'AWS_ACCESS_KEY_ID' => $key, 'AWS_SECRET_ACCESS_KEY' => $key],
        static fn(string $value): bool => $value !== '',
    );
    $sent = sendingOf($environment, $built, new ProofStoreFake())
        ->sent(sendingLedger(Scope::branch('main')), Scope::branch('main'), static fn(): Ledger => Ledger::empty());

    expect([...$built])->toBe([])
        ->and($sent->said())->toBe([$why])
        ->and($sent->exit())->toBe(ExitCode::CannotJudge);
})->with([
    'none' => ['', '', 'id', 'The ledger is not written: MUTATION_GATE_STORE names no built-in store that needs credentials: s3, gcs or azure.'],
    'the directory' => ['directory', '', 'id', 'The ledger is not written: MUTATION_GATE_STORE names no built-in store that needs credentials: s3, gcs or azure.'],
    'a class' => ['Acme\\Store', 'proofs', 'id', 'The ledger is not written: MUTATION_GATE_STORE names no built-in store that needs credentials: s3, gcs or azure.'],
    'no keys' => ['s3', 'proofs', '', 'The ledger is not written: MUTATION_GATE_STORE names s3, whose credentials this job does not hold.'],
    'no bucket' => ['s3', '', 'id', 'The ledger is not written: MUTATION_GATE_STORE_BUCKET: expected a bucket name, got nothing'],
]);

it('sends no store credential to an endpoint its own environment names over plain HTTP', function (): void {
    $built = new ArrayObject();
    $store = new ProofStoreFake();
    $sent = sendingOf([...SENDING_STORE, 'MUTATION_GATE_STORE_ENDPOINT' => 'http://minio.internal'], $built, $store)
        ->sent(sendingLedger(Scope::branch('main')), Scope::branch('main'), static fn(): Ledger => Ledger::empty()->atBase(Digest::sha256Of('base')));

    expect([...$built])->toBe([])
        ->and($store->read(Scope::branch('main')))->toEqual(Ledger::empty())
        ->and($sent->said())->toBe(['The ledger is not written: MUTATION_GATE_STORE_ENDPOINT is not an https:// URL, so no store is located there.'])
        ->and($sent->exit())->toBe(ExitCode::CannotJudge);
});

it('fails, and writes nothing, where the ledger beside the delivery cannot be read', function (Ledger|CannotJudge|TooLarge $unread): void {
    $store = new ProofStoreFake();
    $sent = sendingOf(SENDING_STORE, new ArrayObject(), $store)->sent(sendingLedger(Scope::branch('main')), Scope::branch('main'), static fn(): Ledger|CannotJudge|TooLarge => $unread);

    expect($store->read(Scope::branch('main')))->toEqual(Ledger::empty())
        ->and($sent->said())->toBe(['The ledger is not written, since it cannot be read: past the limit'])
        ->and($sent->exit())->toBe(ExitCode::CannotJudge);
})->with([
    'unreadable' => [CannotJudge::because('past the limit')],
    'too large' => [TooLarge::because('past the limit')],
]);

it('reads no ledger beside the delivery where its own environment locates no store', function (): void {
    $read = new ArrayObject();
    sendingOf([], new ArrayObject(), new ProofStoreFake())->sent(
        sendingLedger(Scope::branch('main')),
        Scope::branch('main'),
        static function () use ($read): Ledger {
            $read->append('read');

            return Ledger::empty();
        },
    );

    expect([...$read])->toBe([]);
});

it('writes no ledger whose scope leads anywhere but its own run\'s, however it is spelled', function (string $ref): void {
    $built = new ArrayObject();
    $store = new ProofStoreFake();
    $sent = sendingOf(SENDING_STORE, $built, $store)
        ->sent(sendingLedger(Scope::of($ref)), Scope::branch('main'), static fn(): Ledger => Ledger::empty()->atBase(Digest::sha256Of('base')));

    expect([...$built])->toBe([])
        ->and($store->read(Scope::branch('main')))->toEqual(Ledger::empty())
        ->and($sent->said())->toBe([sprintf('The ledger is not written: the delivery holds %s\'s, and this run writes refs/heads/main\'s alone.', $ref)])
        ->and($sent->exit())->toBe(ExitCode::CannotJudge);
})->with([
    'up and out' => ['refs/heads/main/../../other'],
    'absolute' => ['/refs/heads/main'],
    'encoded' => ['refs/heads/%2e%2e/main'],
    'an encoded separator' => ['refs%2Fheads%2Fmain'],
    'beneath it' => ['refs/heads/main/x'],
    'a trailing separator' => ['refs/heads/main/'],
]);

it('sends each alert to its channel\'s own variable, signs the webhook with its own secret, and exports to OpenTelemetry\'s endpoint', function (): void {
    $slack = new MockResponse('ok');
    $webhook = new MockResponse('ok');
    $metrics = new MockResponse('{}');
    $environment = [
        'MUTATION_GATE_SLACK_URL' => 'https://hooks.example/slack',
        'MUTATION_GATE_WEBHOOK_URL' => 'https://hooks.example/webhook',
        'MUTATION_GATE_WEBHOOK_SECRET' => 'shh',
        'OTEL_EXPORTER_OTLP_ENDPOINT' => 'https://otel.example',
        'OTEL_EXPORTER_OTLP_HEADERS' => 'api-key=secret',
    ];
    $delivery = Delivery::none()
        ->withAlert(AlertPost::of(BuiltinReporter::Slack, '{"text": "fell"}'))
        ->withAlert(AlertPost::of(BuiltinReporter::Webhook, '{"event": "fell"}'))
        ->withOtlp(OtlpPost::of(NotGiven::value(), '{"resourceMetrics": []}'));
    $sent = sendingOf($environment, new ArrayObject(), new ProofStoreFake(), [$slack, $webhook, $metrics])
        ->sent($delivery, NotWritten::because('untrusted'), static fn(): Ledger => Ledger::empty());

    expect([$slack->getRequestUrl(), $webhook->getRequestUrl(), $metrics->getRequestUrl()])
        ->toBe(['https://hooks.example/slack', 'https://hooks.example/webhook', 'https://otel.example/v1/metrics'])
        ->and(sendingHeaders($webhook))->toContain('X-Mutation-Gate-Signature')
        ->and($slack->getRequestOptions()['body'])->toBe('{"text": "fell"}')
        ->and(sendingHeaders($metrics))->toContain('api-key: secret')
        ->and($sent->exit())->toBe(ExitCode::Passed);
});

it('says an alert goes nowhere where its channel\'s own variable is not set', function (): void {
    $sent = sendingOf([], new ArrayObject(), new ProofStoreFake())
        ->sent(Delivery::none()->withAlert(AlertPost::of(BuiltinReporter::Discord, '{}')), NotWritten::because('untrusted'), static fn(): Ledger => Ledger::empty());

    expect($sent->said())->toBe(['MUTATION_GATE_DISCORD_URL is not set, so no alert goes to Discord.']);
});

it('comments on the pull request its own event names, in its own repository', function (): void {
    $event = Scratch::directory();
    Scratch::write($event, 'event.json', '{"pull_request": {"number": 7, "head": {"repo": {"fork": false}}}}');
    $user = new MockResponse('{"login": "github-actions[bot]"}');
    $listed = new MockResponse('[]');
    $posted = new MockResponse('{"html_url": "https://github.com/octo/gate/pull/7#issuecomment-1"}');
    $environment = [
        'GITHUB_EVENT_NAME' => 'pull_request',
        'GITHUB_EVENT_PATH' => sprintf('%s/event.json', $event),
        'GITHUB_REPOSITORY' => 'octo/gate',
        'GITHUB_TOKEN' => 'token',
        'GITHUB_API_URL' => 'https://api.github.com',
    ];
    $sent = sendingOf($environment, new ArrayObject(), new ProofStoreFake(), [$user, $listed, $posted])
        ->sent(Delivery::none()->withComment('## mutation-gate'), NotWritten::because('untrusted'), static fn(): Ledger => Ledger::empty());

    expect($posted->getRequestMethod())->toBe('POST')
        ->and($posted->getRequestUrl())->toBe('https://api.github.com/repos/octo/gate/issues/7/comments')
        ->and($sent->said())->toBe(['Wrote https://github.com/octo/gate/pull/7#issuecomment-1.']);
});

it('sends no alert for a reporter that sends none', function (): void {
    $sent = sendingOf([], new ArrayObject(), new ProofStoreFake())
        ->sent(Delivery::none()->withAlert(AlertPost::of(BuiltinReporter::Json, '{}')), NotWritten::because('untrusted'), static fn(): Ledger => Ledger::empty());

    expect($sent->said())->toBe(['json sends no alert.']);
});
