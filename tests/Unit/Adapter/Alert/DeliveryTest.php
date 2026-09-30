<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Alert\Delivery;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Support\StoppedClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A delivery over these answers, keeping the seconds it waited.
 *
 * @param list<MockResponse>    $answers
 * @param ArrayObject<int, int> $waited
 */
function deliveryOver(array $answers, ArrayObject $waited): Delivery
{
    return Delivery::over(
        new MockHttpClient($answers),
        new StoppedClock('2026-09-30T12:00:00Z'),
        static function (int $seconds) use ($waited): void {
            $waited[] = $seconds;
        },
    );
}

it('posts the body with its headers, within 10 seconds, and says where it went', function (): void {
    $answer = new MockResponse('ok');
    $waited = new ArrayObject();
    $sent = deliveryOver([$answer], $waited)->post('https://hooks.example/a', '{"a": 1}', ['Content-Type' => 'application/json'], 'Slack');

    expect($sent)->toEqual(Written::to('Slack'))
        ->and($answer->getRequestMethod())->toBe('POST')
        ->and($answer->getRequestUrl())->toBe('https://hooks.example/a')
        ->and($answer->getRequestOptions()['body'])->toBe('{"a": 1}')
        ->and($answer->getRequestOptions()['max_duration'])->toBe(10.0)
        ->and($answer->getRequestOptions()['headers'])->toContain('Content-Type: application/json')
        ->and($waited->getArrayCopy())->toBe([]);
});

it('tries once more after a 429 or a 5xx, waiting what it asks up to 30 seconds', function (string $retryAfter, int $status, int $wait): void {
    $waited = new ArrayObject();
    $answers = [new MockResponse('slow down', ['http_code' => $status, 'response_headers' => $retryAfter === '' ? [] : ['Retry-After' => $retryAfter]]), new MockResponse('ok')];

    expect(deliveryOver($answers, $waited)->post('https://hooks.example/a', '{}', [], 'Discord'))->toEqual(Written::to('Discord'))
        ->and($waited->getArrayCopy())->toBe([$wait]);
})->with([
    'seconds' => ['7', 429, 7],
    'more seconds than it waits' => ['120', 429, 30],
    'a date' => ['Wed, 30 Sep 2026 12:00:12 GMT', 503, 12],
    'a date gone by' => ['Wed, 30 Sep 2026 11:00:00 GMT', 500, 0],
    'nothing' => ['', 502, 0],
    'something else' => ['soon', 429, 0],
]);

it('says not written, with the status and the answer, where it is refused or refused again', function (): void {
    $waited = new ArrayObject();
    $refused = deliveryOver([new MockResponse('invalid_token', ['http_code' => 403])], $waited)->post('https://hooks.example/a', '{}', [], 'Slack');
    $again = deliveryOver([new MockResponse('busy', ['http_code' => 503]), new MockResponse(str_repeat('x', 300), ['http_code' => 503])], $waited)
        ->post('https://hooks.example/a', '{}', [], 'the webhook');

    expect($refused)->toEqual(NotWritten::because('Slack answered 403: invalid_token'))
        ->and($again)->toEqual(NotWritten::because(sprintf('the webhook answered 503: %s…', str_repeat('x', 199))));
});

it('says not written where the URL cannot be reached', function (): void {
    $sent = deliveryOver([new MockResponse('', ['error' => 'Could not resolve host'])], new ArrayObject())->post('https://hooks.example/a', '{}', [], 'Discord');

    expect($sent)->toBeInstanceOf(NotWritten::class)
        ->and($sent instanceof NotWritten ? $sent->why() : '')->toStartWith('Discord could not be reached: ');
});

it('posts over the network where it is not handed a client', function (): void {
    expect(Delivery::online(new StoppedClock('2026-09-30T12:00:00Z')))->toBeInstanceOf(Delivery::class);
});

it('follows no redirect, so the body and its signature reach only the URL given', function (): void {
    $answer = new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location' => 'https://elsewhere.example/']]);
    $sent = deliveryOver([$answer], new ArrayObject())->post('https://hooks.example/a', '{}', [], 'the webhook');

    expect($answer->getRequestOptions()['max_redirects'])->toBe(0)
        ->and($sent)->toEqual(NotWritten::because('the webhook answered 302: '));
});

it('never repeats a URL, which holds the chat\'s credential, however the client spells it', function (): void {
    $url = 'https://hooks.example/services/T000/B000/secret';
    $message = sprintf('Max duration was reached for "%s" after "HTTPS://Hooks.Example/services/T000/B000/secret?x=1".', $url);
    $sent = deliveryOver([new MockResponse('', ['error' => $message])], new ArrayObject())->post($url, '{}', [], 'Slack');
    $why = $sent instanceof NotWritten ? $sent->why() : '';

    expect($why)->toBe('Slack could not be reached: Max duration was reached for "Slack" after "Slack".')
        ->and($why)->not->toContain('secret');
});

it('says what a service answered on one plain line, so it can start no workflow command', function (): void {
    $answer = new MockResponse("bad\n::error file=src/x.php::owned\r\n\e[31mred\e[0m\t\x07end", ['http_code' => 400]);
    $sent = deliveryOver([$answer], new ArrayObject())->post('https://hooks.example/a', '{}', [], 'Discord');
    $why = $sent instanceof NotWritten ? $sent->why() : '';

    expect($why)->toBe('Discord answered 400: bad ::error file=src/x.php::owned [31mred[0m end')
        ->and($why)->not->toContain("\n")
        ->and($why)->not->toContain("\e");
});
