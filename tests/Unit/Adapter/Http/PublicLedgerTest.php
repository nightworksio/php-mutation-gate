<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Http\PublicLedger;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\LedgerFile;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Time\Instant;
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

    expect($read->proofs()->has(Digest::sha256Of('src/Money.php')))->toBeTrue()
        ->and($sent->getArrayCopy())
        ->toBe([['GET', 'https://ledgers.example.com/mutation-gate/refs/heads/main/ledger.json.gz', false, 0, 60.0]]);
});

it('reads an empty ledger for a scope with none yet, a refusal, a failed request, or a body past its size', function (
    MockResponse $answer,
    int $largest,
): void {
    [$client] = publicLedgerServer($answer);
    $store = PublicLedger::at($client, PUBLIC_LEDGERS, 'mutation-gate')->atMost($largest);

    expect($store->read(Scope::pullRequest(12)))->toEqual(Ledger::empty());
})->with([
    'no ledger yet' => [new MockResponse('', ['http_code' => 404]), PHP_INT_MAX],
    'a server\'s error' => [new MockResponse('down', ['http_code' => 503]), PHP_INT_MAX],
    'a redirect it does not follow' => [new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: https://elsewhere.example.com/']]), PHP_INT_MAX],
    'a request that times out' => [new MockResponse('', ['error' => 'Idle timeout reached']), PHP_INT_MAX],
    'a body past the most it reads' => [new MockResponse(LedgerFile::encode(publicLedgerProved())), 10],
]);

it('reads a body of exactly the most it reads', function (): void {
    $bytes = LedgerFile::encode(publicLedgerProved());
    [$client] = publicLedgerServer(new MockResponse($bytes));
    $store = PublicLedger::at($client, PUBLIC_LEDGERS, 'mutation-gate')->atMost(strlen($bytes));

    expect($store->read(Scope::branch('main'))->proofs()->has(Digest::sha256Of('src/Money.php')))->toBeTrue();
});

it('writes nothing, sends no request, and says it is read-only and where it reads from', function (): void {
    [$client, $sent] = publicLedgerServer();

    expect(PublicLedger::at($client, PUBLIC_LEDGERS, 'mutation-gate')->write(Scope::branch('main'), publicLedgerProved()))
        ->toEqual(NotWritten::because(
            'read-only: no credentials; this run\'s proofs are not kept. The ledgers are read from https://ledgers.example.com.',
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

it('asks for no ledger of a ref that is no scope', function (): void {
    [$client, $sent] = publicLedgerServer();

    expect(PublicLedger::at($client, PUBLIC_LEDGERS, 'mutation-gate')->read(Scope::of('refs/tags/v1')))->toEqual(Ledger::empty())
        ->and($sent->count())->toBe(0);
});
