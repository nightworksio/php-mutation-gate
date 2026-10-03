<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Http\HttpExchange;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Http\MediaType;
use NightWorksIO\MutationGate\Core\Http\Reply;
use NightWorksIO\MutationGate\Core\Http\Request;
use NightWorksIO\MutationGate\Core\Http\Token;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\LedgerLimits;
use NightWorksIO\MutationGate\Core\Proof\Unreadable;
use NightWorksIO\MutationGate\Core\Proof\UnreadReason;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Support\Cloud;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

it('sends a request\'s method, URL, headers and body, within the limits\' seconds and following no redirect', function (): void {
    $sent = [];
    $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent): MockResponse {
        $headers = is_array($options['normalized_headers'] ?? null) ? $options['normalized_headers'] : [];
        $sent = [$method, $url, $options['body'] ?? '', $options['max_duration'] ?? 0, $options['max_redirects'] ?? 1, $headers['authorization'] ?? []];

        return new MockResponse('{"value": "jwt"}', ['http_code' => 200]);
    });
    $request = Request::post('https://token.example/exchange', 'a=b')->carrying(Token::bearer('t'))->sending(MediaType::Form);

    expect(HttpExchange::over($client)->answer($request))->toEqual(Reply::of(200, '', '{"value": "jwt"}'))
        ->and($sent)->toBe(['POST', 'https://token.example/exchange', 'a=b', 60.0, 0, ['Authorization: Bearer t']]);
});

it('answers what a refusal says, and why no answer came, naming the place by its origin alone', function (): void {
    $refusing = new MockHttpClient(new MockResponse('{"error": "invalid_grant"}', ['http_code' => 400]));
    $unreached = new MockHttpClient(new MockResponse('', ['error' => 'Could not resolve host for "https://token.example/path?secret=1"']));

    expect(HttpExchange::over($refusing)->answer(Request::get('https://token.example/path')))->toEqual(Reply::of(400, '', '{"error": "invalid_grant"}'))
        ->and(HttpExchange::over($unreached)->answer(Request::get('https://token.example/path?secret=1')))
        ->toEqual(CannotJudge::because('https://token.example could not be reached: Could not resolve host for "https://token.example"'));
});

it('fetches a ledger\'s bytes, an empty ledger where none is there, and why the rest are unread', function (): void {
    $cloud = new Cloud()->holding('https://ledgers.example/main', 'bytes')->answering('https://ledgers.example/denied', 403, 'no');
    $exchange = $cloud->exchange();
    $limits = LedgerLimits::standard();

    expect($exchange->fetch(Request::get('https://ledgers.example/main'), $limits, 'gs://ledgers/main'))->toBe('bytes')
        ->and($exchange->fetch(Request::get('https://ledgers.example/none'), $limits, 'gs://ledgers/none'))->toEqual(Ledger::empty())
        ->and($exchange->fetch(Request::get('https://ledgers.example/denied'), $limits, 'gs://ledgers/denied'))
        ->toEqual(Unreadable::because(UnreadReason::Refused, 'gs://ledgers/denied', 'HTTP 403'))
        ->and(HttpExchange::over(new MockHttpClient(new MockResponse(str_split(str_repeat('x', 30), 10))))->fetch(
            Request::get('https://ledgers.example/large'),
            LedgerLimits::of(25, 38_000_000, 60.0),
            'gs://ledgers/large',
        ))->toEqual(Unreadable::because(UnreadReason::TooLarge, 'gs://ledgers/large', 'it is larger than 25 bytes'));
});

it('says a ledger it could not fetch was unreachable, or did not come in time', function (): void {
    $unreached = new MockHttpClient(new MockResponse('', ['error' => 'Could not resolve host']));
    $slow = new MockHttpClient(new MockResponse((static function (): Generator {
        yield '';
    })()));

    expect(HttpExchange::over($unreached)->fetch(Request::get('https://ledgers.example/main'), LedgerLimits::standard(), 'gs://ledgers/main'))
        ->toEqual(Unreadable::because(UnreadReason::Unreachable, 'gs://ledgers/main', 'Could not resolve host'))
        ->and(HttpExchange::over($slow)->fetch(Request::get('https://ledgers.example/main'), LedgerLimits::standard(), 'gs://ledgers/main'))
        ->toEqual(Unreadable::because(UnreadReason::TimedOut, 'gs://ledgers/main', 'no answer came in time'));
});

it('puts a ledger, saying where it went, or why it did not', function (): void {
    $cloud = new Cloud()->answering('https://ledgers.example/denied', 403, 'AuthorizationPermissionMismatch');
    $unreached = new MockHttpClient(new MockResponse('', ['error' => 'Could not resolve host']));

    expect($cloud->exchange()->put(Request::put('https://ledgers.example/main', 'bytes'), LedgerLimits::standard(), 'gs://ledgers/main'))
        ->toEqual(Written::to('gs://ledgers/main'))
        ->and($cloud->exchange()->put(Request::put('https://ledgers.example/denied', 'bytes'), LedgerLimits::standard(), 'gs://ledgers/denied'))
        ->toEqual(NotWritten::because('gs://ledgers/denied answered 403: AuthorizationPermissionMismatch'))
        ->and(HttpExchange::over($unreached)->put(Request::put('https://ledgers.example/main', 'bytes'), LedgerLimits::standard(), 'gs://ledgers/main'))
        ->toBeInstanceOf(NotWritten::class)
        ->and($cloud->requests[0]['body'])->toBe('bytes');
});

it('reads no answer past the limits\' bytes, whether a token\'s or a refused write\'s', function (): void {
    $large = str_repeat('x', LedgerLimits::standard()->packed() + 1);
    $client = new MockHttpClient(static fn(string $method): MockResponse => new MockResponse($large, ['http_code' => $method === 'PUT' ? 403 : 200]));
    $past = sprintf('answered, and its answer was not read: it is larger than %d bytes', LedgerLimits::standard()->packed());

    expect(HttpExchange::over($client)->answer(Request::get('https://token.example/path?q=1')))
        ->toEqual(CannotJudge::because(sprintf('https://token.example %s', $past)))
        ->and(HttpExchange::over($client)->put(Request::put('https://ledgers.example/main', 'bytes'), LedgerLimits::standard(), 'gs://ledgers/main'))
        ->toEqual(NotWritten::because(sprintf('gs://ledgers/main %s', $past)));
});
