<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Bitbucket\CodeInsights;
use NightWorksIO\MutationGate\Adapter\Bitbucket\InsightAnnotation;
use NightWorksIO\MutationGate\Adapter\Http\HttpExchange;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Cluster\Cluster;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Report\AnnotationOrder;
use NightWorksIO\MutationGate\Core\Report\Overview;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Support\Cloud;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

const INSIGHTS_REPORT = 'http://api.bitbucket.org/2.0/repositories/acme/shop/commit/5eeca8f/reports/mutation-gate';

/** The variables of a Bitbucket Pipelines step building the commit 5eeca8f of acme/shop. */
function insightsStep(): Variables
{
    return Variables::of([
        'BITBUCKET_BUILD_NUMBER' => '99',
        'BITBUCKET_REPO_FULL_NAME' => 'acme/shop',
        'BITBUCKET_WORKSPACE' => 'acme',
        'BITBUCKET_REPO_SLUG' => 'shop',
        'BITBUCKET_BRANCH' => 'feature',
        'BITBUCKET_COMMIT' => '5eeca8f',
    ]);
}

/** A cloud that takes the report and its annotations. */
function insightsCloud(): Cloud
{
    return new Cloud()
        ->answering(INSIGHTS_REPORT, 200, '{"uuid": "{r}"}')
        ->answering(sprintf('%s/annotations', INSIGHTS_REPORT), 200, '[]');
}

/**
 * This many mutants of a file, each on a line of its own, judged so.
 *
 * @return list<JudgedMutant>
 */
function insightsMutants(string $file, int $count, MutantJudgement $judged): array
{
    return array_map(
        static fn(int $line): JudgedMutant => JudgedMutant::of(
            Verdicts::mutant(sprintf('%s:%d', $file, $line), 'Plus', MutatorFamily::Arithmetic, Verdicts::diff('$a + 1;', '$a - 1;')),
            $judged,
        ),
        range(1, $count),
    );
}

/** A verdict whose one tree, held to 80, has this many survivors. */
function insightsSurvivors(int $count): Verdict
{
    return Verdicts::of(Floor::of(80), ...insightsMutants('src/Big.php', $count, MutantJudgement::Survived));
}

it('writes the report of the commit through the proxy, with no token, after deleting the one an earlier run wrote', function (): void {
    $cloud = insightsCloud();
    $answer = CodeInsights::inEnvironment(insightsStep(), $cloud->exchange())->report(Verdicts::failing());
    $report = $cloud->requests[1]['body'];

    expect($answer)->toEqual(Written::to('the Code Insights report of commit 5eeca8f'))
        ->and(array_map(static fn(array $request): string => sprintf('%s %s', $request['method'], $request['url']), $cloud->requests))->toBe([
            sprintf('DELETE %s', INSIGHTS_REPORT),
            sprintf('PUT %s', INSIGHTS_REPORT),
            sprintf('POST %s/annotations', INSIGHTS_REPORT),
        ])
        ->and(array_column(array_column($cloud->requests, 'headers'), 'authorization'))->toBe([])
        ->and(Decoded::at($report, 'title'))->toBe('mutation-gate')
        ->and(Decoded::at($report, 'reporter'))->toBe('mutation-gate')
        ->and(Decoded::at($report, 'report_type'))->toBe('BUG')
        ->and(Decoded::at($report, 'result'))->toBe('FAILED')
        ->and(Decoded::at($report, 'link'))->toBe('https://bitbucket.org/acme/shop/pipelines/results/99')
        ->and(Decoded::column($report, 'title', 'data'))->toBe(['Score', 'Not killed'])
        ->and(Decoded::at($report, 'data', 1, 'value'))->toBe(count(Overview::of(Verdicts::failing())->survivors()));
});

it('writes the report of its verdict, the project\'s score, how many mutants are not killed, and the run', function (): void {
    $cloud = insightsCloud();
    $mutants = [
        ...insightsMutants('src/Kept.php', 9, MutantJudgement::Killed),
        ...insightsMutants('src/Big.php', 3, MutantJudgement::Survived),
    ];
    CodeInsights::inEnvironment(insightsStep(), $cloud->exchange())->report(Verdicts::of(Floor::of(80), ...$mutants));

    expect(json_decode($cloud->requests[1]['body'], associative: true))->toBe([
        'title' => 'mutation-gate',
        'details' => 'The project scores 75.00%.',
        'report_type' => 'BUG',
        'reporter' => 'mutation-gate',
        'link' => 'https://bitbucket.org/acme/shop/pipelines/results/99',
        'result' => 'FAILED',
        'data' => [
            ['title' => 'Score', 'type' => 'PERCENTAGE', 'value' => 75.0],
            ['title' => 'Not killed', 'type' => 'NUMBER', 'value' => 3],
        ],
    ]);
});

it('writes a report with no score where there is nothing to mutate', function (): void {
    $cloud = insightsCloud();
    CodeInsights::inEnvironment(insightsStep(), $cloud->exchange())->report(Verdicts::empty());

    expect(Decoded::at($cloud->requests[1]['body'], 'details'))->toBe('The project has nothing to mutate.')
        ->and(Decoded::at($cloud->requests[1]['body'], 'data'))->toBe([['title' => 'Not killed', 'type' => 'NUMBER', 'value' => 0]]);
});

it('writes through the proxy every Bitbucket Pipelines step reaches Code Insights through', function (): void {
    expect(CodeInsights::CLIENT)->toBe(['proxy' => 'http://localhost:29418']);
});

it('passes a verdict that passes, and posts no annotation where every mutant is killed', function (): void {
    $cloud = insightsCloud();
    $answer = CodeInsights::inEnvironment(insightsStep(), $cloud->exchange())->report(Verdicts::passing());

    expect($answer)->toBeInstanceOf(Written::class)
        ->and(Decoded::at($cloud->requests[1]['body'], 'result'))->toBe('PASSED')
        ->and(array_column($cloud->requests, 'method'))->toBe(['DELETE', 'PUT']);
});

it('annotates each mutant not killed, ranked as line annotations are, those in a set that failed as high', function (): void {
    $cloud = insightsCloud();
    CodeInsights::inEnvironment(insightsStep(), $cloud->exchange())->report(Verdicts::failing());
    $order = AnnotationOrder::of(Verdicts::failing());
    $gate = Overview::of(Verdicts::failing())->gate();
    $expected = [
        ...array_map(static fn(JudgedMutant|Cluster $item): string => InsightAnnotation::of($item, failing: true, gate: $gate)->line(), $order->failing()),
        ...array_map(static fn(JudgedMutant|Cluster $item): string => InsightAnnotation::of($item, failing: false, gate: $gate)->line(), $order->other()),
    ];

    expect($cloud->requests[2]['body'])->toBe(sprintf('[%s]', implode(',', $expected)))
        ->and($expected)->not->toBe([]);
});

it('writes one annotation of a mutant at its file and line, saying what survived and how to reproduce it', function (): void {
    $survivor = Verdicts::survivor();
    $annotation = InsightAnnotation::of($survivor, failing: false, gate: Overview::of(Verdicts::failing())->gate())->line();

    expect(Decoded::at($annotation, 'external_id'))->toBe(sprintf('mutation-gate-%s', $survivor->mutant()->id()->value()))
        ->and(Decoded::at($annotation, 'annotation_type'))->toBe('BUG')
        ->and(Decoded::at($annotation, 'result'))->toBe('FAILED')
        ->and(Decoded::at($annotation, 'severity'))->toBe('MEDIUM')
        ->and(Decoded::at($annotation, 'path'))->toBe('src/Money.php')
        ->and(Decoded::at($annotation, 'line'))->toBe(7)
        ->and(Decoded::at($annotation, 'summary'))->toStartWith('Mutant ')
        ->and(Decoded::at($annotation, 'details'))->toContain('Reproduce: ');
});

it('annotates a survivor in a set that passed as medium, in a report that passes', function (): void {
    $cloud = insightsCloud();
    $mutants = [
        ...insightsMutants('src/Kept.php', 9, MutantJudgement::Killed),
        ...insightsMutants('src/Big.php', 1, MutantJudgement::Survived),
    ];
    CodeInsights::inEnvironment(insightsStep(), $cloud->exchange())->report(Verdicts::of(Floor::of(50), ...$mutants));

    expect(Decoded::at($cloud->requests[1]['body'], 'result'))->toBe('PASSED')
        ->and(Decoded::column($cloud->requests[2]['body'], 'severity'))->toBe(['MEDIUM'])
        ->and(Decoded::column($cloud->requests[2]['body'], 'path'))->toBe(['src/Big.php']);
});

it('marks a mutant in a set that failed as high, and cuts its summary and details to fit', function (): void {
    $long = JudgedMutant::of(
        Verdicts::mutant('src/A.php:3', str_repeat('M', 600), MutatorFamily::Arithmetic, Verdicts::diff(str_repeat('$a + 1; ', 400), '$a - 1;')),
        MutantJudgement::Survived,
    );
    $annotation = InsightAnnotation::of($long, failing: true, gate: Overview::of(Verdicts::failing())->gate())->line();
    $summary = Decoded::at($annotation, 'summary');
    $details = Decoded::at($annotation, 'details');

    expect(Decoded::at($annotation, 'severity'))->toBe('HIGH')
        ->and(is_string($summary) ? mb_strlen($summary) : 0)->toBe(450)
        ->and(is_string($details) ? mb_strlen($details) : 0)->toBe(2_000)
        ->and($summary)->toEndWith('…')
        ->and($details)->toEndWith('…');
});

it('posts at most 1,000 annotations, 100 a request', function (): void {
    $cloud = insightsCloud();
    CodeInsights::inEnvironment(insightsStep(), $cloud->exchange())->report(insightsSurvivors(1_050));
    $posts = array_values(array_filter($cloud->requests, static fn(array $request): bool => $request['method'] === 'POST'));

    expect($posts)->toHaveCount(10)
        ->and(array_map(static fn(array $post): int => count(is_array($items = json_decode($post['body'], associative: true)) ? $items : []), $posts))
        ->toBe(array_fill(0, 10, 100));
});

it('writes no report, and says why, on a run that is no Bitbucket Pipelines step', function (): void {
    $cloud = new Cloud();

    expect(CodeInsights::inEnvironment(Variables::of(['BITBUCKET_BUILD_NUMBER' => '99']), $cloud->exchange())->report(Verdicts::failing()))
        ->toEqual(NotWritten::because('This run is no Bitbucket Pipelines step, so no Code Insights report is written.'))
        ->and($cloud->requests)->toBe([]);
});

it('writes a report where none was there to delete', function (): void {
    $methods = [];
    $client = new MockHttpClient(static function (string $method) use (&$methods): MockResponse {
        $methods[] = $method;

        return $method === 'DELETE'
            ? new MockResponse('{"type": "error"}', ['http_code' => 404])
            : new MockResponse('{}', ['http_code' => 200]);
    });

    expect(CodeInsights::inEnvironment(insightsStep(), HttpExchange::over($client))->report(Verdicts::passing()))
        ->toEqual(Written::to('the Code Insights report of commit 5eeca8f'))
        ->and($methods)->toBe(['DELETE', 'PUT']);
});

it('says why, naming Bitbucket by its origin alone, where it refuses the report or its annotations', function (string $refused, int $requests): void {
    $cloud = insightsCloud()->answering($refused, 403, '{"error": {"message": "no"}}');
    $answer = CodeInsights::inEnvironment(insightsStep(), $cloud->exchange())->report(insightsSurvivors(150));

    expect($answer)->toEqual(NotWritten::because(
        'The Code Insights report could not be written (http://api.bitbucket.org answered 403: {"error": {"message": "no"}}).',
    ))->and($cloud->requests)->toHaveCount($requests);
})->with([
    'the report' => [INSIGHTS_REPORT, 1],
    'the annotations' => [fn(): string => sprintf('%s/annotations', INSIGHTS_REPORT), 3],
]);
