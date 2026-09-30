<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Http\PublicLedger;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Gzip;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\LedgerFile;
use NightWorksIO\MutationGate\Core\Proof\LedgerLimits;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Unreadable;
use NightWorksIO\MutationGate\Core\Proof\UnreadReason;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Tests\Support\CountingClient;
use NightWorksIO\MutationGate\Tests\Support\LedgerRead;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

const PUBLIC_LEDGERS = 'https://ledgers.example.com';

/** A ledger holding one proof of src/Money.php. */
function publicLedgerProved(): Ledger
{
    $run = Run::of('github:1/1', Instant::at(new DateTimeImmutable('2026-09-29T20:48:17Z')), Digest::of(str_repeat('b', 64)));

    return Ledger::empty()
        ->withProof(Proof::of(Digest::sha256Of('src/Money.php'), Path::of('src/Money.php'), Mutants::none(), $run))
        ->atBase($run->base());
}

/**
 * A client that answers each request with the next of these, and what it was sent: the method, the URL, whether
 * it was signed, and the redirects and seconds it allowed.
 *
 * @return array{MockHttpClient, ArrayObject<int, array{string, string, bool, mixed, mixed}>}
 */
function publicLedgerServer(MockResponse ...$answers): array
{
    /** @var ArrayObject<int, array{string, string, bool, mixed, mixed}> $sent */
    $sent = new ArrayObject();
    $client = new MockHttpClient(static function (string $method, string $url, array $options) use (
        $sent,
        &$answers,
    ): MockResponse {
        $headers = $options['normalized_headers'] ?? [];
        $sent->append([
            $method,
            $url,
            is_array($headers) && array_key_exists('authorization', $headers),
            $options['max_redirects'] ?? null,
            $options['max_duration'] ?? null,
        ]);

        return array_shift($answers) ?? new MockResponse('', ['http_code' => 404]);
    });

    return [$client, $sent];
}

it('reads a scope\'s ledger with an anonymous GET under the prefix at the public URL', function (): void {
    [$client, $sent] = publicLedgerServer(new MockResponse(LedgerFile::encode(publicLedgerProved())));
    $read = PublicLedger::at($client, sprintf('%s/', PUBLIC_LEDGERS), '/mutation-gate/')->read(Scope::branch('main'));

    expect($read)->toEqual(LedgerFile::decode(LedgerFile::encode(publicLedgerProved())))
        ->and($sent->getArrayCopy())
        ->toBe([['GET', 'https://ledgers.example.com/mutation-gate/refs/heads/main/ledger.json.gz', false, 0, 60.0]]);
});

it('asks for the object the bucket keeps under each segment of a branch\'s name, percent-encoded, and never outside it', function (
    string $branch,
    string $path,
): void {
    [$client, $sent] = publicLedgerServer();
    PublicLedger::at($client, 'https://ledgers.example.com/pub', 'mutation-gate')->read(Scope::branch($branch));

    expect($sent[0][1] ?? '')->toBe(sprintf('https://ledgers.example.com/pub/mutation-gate/refs/heads/%s/ledger.json.gz', $path));
})->with([
    'a plain name' => ['release/2.x', 'release/2.x'],
    'encoded dots that would climb out' => ['%2e%2e/%2e%2e/secret', '%252e%252e/%252e%252e/secret'],
    'a fragment\'s mark' => ['main/ledger.json.gz#', 'main/ledger.json.gz%23'],
    'an escape' => ['z%C3%BCrich', 'z%25C3%25BCrich'],
    'a name beyond ASCII' => ['zürich', 'z%C3%BCrich'],
    'a stray percent' => ['100%', '100%25'],
]);

it('asks for no ledger of a ref that is no scope', function (string $ref): void {
    [$client, $sent] = publicLedgerServer();

    expect(PublicLedger::at($client, PUBLIC_LEDGERS, 'mutation-gate')->read(Scope::of($ref)))->toEqual(Ledger::empty())
        ->and($sent->count())->toBe(0);
})->with([
    'a tag' => ['refs/tags/v1'],
    'a query\'s mark' => ['refs/heads/main?x=1'],
]);

it('reads a scope with no ledger yet as an empty one, though the answer holds a ledger', function (): void {
    [$client] = publicLedgerServer(new MockResponse(LedgerFile::encode(publicLedgerProved()), ['http_code' => 404]));

    expect(PublicLedger::at($client, PUBLIC_LEDGERS, 'mutation-gate')->read(Scope::branch('main')))->toEqual(Ledger::empty());
});

it('says why a ledger could not be read, and reads none of what came with a refusal', function (
    MockResponse $answer,
    UnreadReason $reason,
    string $why,
): void {
    [$client] = publicLedgerServer($answer);
    $read = PublicLedger::at($client, PUBLIC_LEDGERS, 'mutation-gate')->read(Scope::branch('main'));

    expect($read)->toBeInstanceOf(Unreadable::class)
        ->and($read instanceof Unreadable ? [$read->reason(), $read->why(), count($read->ledger()->proofs())] : [])
        ->toBe([$reason, sprintf(
            'The ledger is unreadable from %s/mutation-gate/refs/heads/main/ledger.json.gz: %s. The run judges without it.',
            PUBLIC_LEDGERS,
            $why,
        ), 0]);
})->with([
    'a server\'s error' => [new MockResponse(LedgerFile::encode(publicLedgerProved()), ['http_code' => 503]), UnreadReason::Refused, 'HTTP 503'],
    'a denied read' => [new MockResponse(LedgerFile::encode(publicLedgerProved()), ['http_code' => 403]), UnreadReason::Refused, 'HTTP 403'],
    'a redirect it does not follow' => [
        new MockResponse(LedgerFile::encode(publicLedgerProved()), ['http_code' => 302, 'response_headers' => ['Location: https://elsewhere.example.com/']]),
        UnreadReason::Refused,
        'HTTP 302',
    ],
    'no ledger this gate reads' => [new MockResponse('not a ledger'), UnreadReason::Malformed, 'The ledger is not a whole gzip stream'],
    'a ledger of another format' => [new MockResponse(Gzip::pack('{"format": 1}')), UnreadReason::Malformed, 'The ledger is of a format this gate does not read'],
]);

it('says the store could not be reached, or did not answer in time', function (): void {
    [$unreached] = publicLedgerServer(new MockResponse('', ['error' => 'Could not resolve host']));
    [$slow] = publicLedgerServer(new MockResponse((static function (): Generator {
        yield '';
    })()));
    $unreachedRead = PublicLedger::at($unreached, PUBLIC_LEDGERS, 'mutation-gate')->read(Scope::branch('main'));
    $slowRead = PublicLedger::at($slow, PUBLIC_LEDGERS, 'mutation-gate')->read(Scope::branch('main'));

    [$reason, $why] = LedgerRead::unread($unreachedRead);

    expect($reason)->toBe(UnreadReason::Unreachable)
        ->and($why)->toContain('Could not resolve host')
        ->and(LedgerRead::unread($slowRead))->toBe([
            UnreadReason::TimedOut,
            sprintf('The ledger is unreadable from %s/mutation-gate/refs/heads/main/ledger.json.gz: no answer came in time. The run judges without it.', PUBLIC_LEDGERS),
        ]);
});

it('stops reading at the chunk that takes the body past the limit, and reads a body of the limit', function (): void {
    $bytes = LedgerFile::encode(publicLedgerProved());
    $client = new CountingClient(new MockHttpClient(new MockResponse(str_split($bytes, 10))));
    $read = PublicLedger::at($client, PUBLIC_LEDGERS, 'mutation-gate')
        ->within(LedgerLimits::of(25, 38_000_000, 60.0))
        ->read(Scope::branch('main'));
    [$exact] = publicLedgerServer(new MockResponse($bytes));
    $whole = PublicLedger::at($exact, PUBLIC_LEDGERS, 'mutation-gate')
        ->within(LedgerLimits::of(strlen($bytes), 38_000_000, 60.0))
        ->read(Scope::branch('main'));

    expect(LedgerRead::unread($read))->toBe([
        UnreadReason::TooLarge,
        sprintf('The ledger is unreadable from %s/mutation-gate/refs/heads/main/ledger.json.gz: it is larger than 25 bytes. The run judges without it.', PUBLIC_LEDGERS),
    ])
        ->and($client->taken)->toBeLessThan(6)
        ->and(strlen($bytes))->toBeGreaterThan(100)
        ->and(count(LedgerRead::ledger($whole)->proofs()))->toBe(1);
});

it('reads a small stream that inflates past the limit as too large, not as memory spent', function (): void {
    $bomb = Gzip::pack(str_repeat('0', 50_000_000));
    [$client] = publicLedgerServer(new MockResponse($bomb));
    $read = PublicLedger::at($client, PUBLIC_LEDGERS, 'mutation-gate')
        ->within(LedgerLimits::of(1_000_000, 1_000_000, 60.0))
        ->read(Scope::branch('main'));

    expect(strlen($bomb))->toBeLessThan(100_000)
        ->and(LedgerRead::unread($read))->toBe([
            UnreadReason::TooLarge,
            sprintf('The ledger is unreadable from %s/mutation-gate/refs/heads/main/ledger.json.gz: The ledger inflates to more than 1000000 bytes. The run judges without it.', PUBLIC_LEDGERS),
        ]);
});

it('reads the default branch\'s scope alone once told which it is, and asks for no other', function (): void {
    [$client, $sent] = publicLedgerServer(new MockResponse(LedgerFile::encode(publicLedgerProved())));
    $store = PublicLedger::at($client, PUBLIC_LEDGERS, 'mutation-gate')->onlyReading(Scope::branch('main'));

    expect($store->read(Scope::pullRequest(12)))->toEqual(Ledger::empty())
        ->and($store->read(Scope::branch('feature')))->toEqual(Ledger::empty())
        ->and($sent->count())->toBe(0)
        ->and(count(LedgerRead::ledger($store->read(Scope::branch('main')))->proofs()))->toBe(1)
        ->and($sent->count())->toBe(1);
});

it('writes nothing, sends no request, and says it is read-only and where it reads from', function (): void {
    [$client, $sent] = publicLedgerServer();

    expect(PublicLedger::at($client, PUBLIC_LEDGERS, 'mutation-gate')->write(Scope::branch('main'), publicLedgerProved()))
        ->toEqual(NotWritten::because(
            'read-only: no credentials; this run\'s proofs are not kept. The default branch\'s ledger is read from https://ledgers.example.com.',
        ))
        ->and($sent->count())->toBe(0);
});

it('reads and writes nothing where no public URL is named, and sends no request', function (): void {
    [$client, $sent] = publicLedgerServer(new MockResponse(LedgerFile::encode(publicLedgerProved())));
    $store = PublicLedger::nowhere($client);

    expect($store->read(Scope::branch('main')))->toEqual(Ledger::empty())
        ->and($store->write(Scope::branch('main'), publicLedgerProved()))->toEqual(NotWritten::because(
            'read-only: no credentials; this run\'s proofs are not kept. No publicUrl names where the default branch\'s ledger is read from.',
        ))
        ->and($sent->count())->toBe(0);
});
