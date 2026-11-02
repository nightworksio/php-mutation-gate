<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\GitHub\Api;
use NightWorksIO\MutationGate\Adapter\GitHub\HtmlReport;
use NightWorksIO\MutationGate\Core\Report\Markdown;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

/** The report of run 7 of octo/gate, asked of GitHub as these responses answer. */
function htmlReport(MockHttpClient $client): HtmlReport
{
    return HtmlReport::of(Api::at($client, 'https://api.github.example', 'secret'), 'octo/gate', '7', 'https://github.example/octo/gate/actions/runs/7');
}

it('links the artifact the run uploaded its HTML report as, found among the run\'s own', function (array $artifact): void {
    $asked = new JsonMockResponse(['total_count' => 1, 'artifacts' => [$artifact]]);

    expect(htmlReport(new MockHttpClient([$asked]))->into(sprintf("before\n\n%s\n", Markdown::REPORT_SLOT)))
        ->toBe("before\n\n[The HTML report](https://github.example/octo/gate/actions/runs/7/artifacts/42) of [this run](https://github.example/octo/gate/actions/runs/7).\n")
        ->and($asked->getRequestUrl())->toBe('https://api.github.example/repos/octo/gate/actions/runs/7/artifacts?name=mutation-gate-report.html');
})->with([
    'not expired' => [['id' => 42, 'expired' => false]],
    'saying nothing of expiry' => [['id' => 42]],
]);

it('says plainly why it links no report, and links the run', function (MockResponse $answer, string $why): void {
    expect(htmlReport(new MockHttpClient([$answer]))->line())
        ->toBe(sprintf('This run left no HTML report to link: %s. [The run](https://github.example/octo/gate/actions/runs/7) holds what it wrote.', $why));
})->with([
    'none uploaded' => [
        fn(): MockResponse => new JsonMockResponse(['total_count' => 0, 'artifacts' => []]),
        'no artifact of it is named `mutation-gate-report.html`, so the verdict wrote none or it was not uploaded',
    ],
    'expired' => [fn(): MockResponse => new JsonMockResponse(['artifacts' => [['id' => 42, 'expired' => true]]]), 'its artifact has expired'],
]);

it('says the run\'s artifacts could not be read where GitHub refuses them', function (): void {
    $line = htmlReport(new MockHttpClient([new MockResponse('{"message": "Resource not accessible by integration"}', ['http_code' => 403])]))->line();

    expect($line)->toStartWith('This run left no HTML report to link: its artifacts could not be read (')
        ->and($line)->toContain('403');
});

it('asks GitHub nothing for a comment that holds no slot', function (): void {
    $client = new MockHttpClient([]);

    expect(htmlReport($client)->into('planned'))->toBe('planned')
        ->and($client->getRequestsCount())->toBe(0);
});
