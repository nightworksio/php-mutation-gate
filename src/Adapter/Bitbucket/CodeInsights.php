<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Bitbucket;

use function array_chunk;
use function array_slice;
use function count;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\CiRun;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Http\Exchange;
use NightWorksIO\MutationGate\Core\Http\Reply;
use NightWorksIO\MutationGate\Core\Http\RestApi;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Report\AnnotationOrder;
use NightWorksIO\MutationGate\Core\Report\Overview;
use NightWorksIO\MutationGate\Core\Report\SetText;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\ThisPackage;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\Reporter;

use function rawurlencode;
use function sprintf;

/**
 * The reporter `bitbucket-insights`: a Code Insights report on the commit a Bitbucket Pipelines step builds,
 * `mutation-gate`, of type `BUG`, `FAILED` where the verdict fails, with one annotation for each mutant counted as
 * not killed, at most 1,000, ranked as line annotations are, and posted 100 a request (ADR-0024). It is
 * written through the pipeline's own proxy, which authorises the request itself, so it holds no token. The report
 * is deleted first, so an annotation of an earlier run of the commit never stays. A step that cannot write it
 * says why and fails nothing.
 */
final readonly class CodeInsights implements Reporter
{
    /**
     * The options of the HTTP client the report is written through: the proxy every Bitbucket Pipelines step
     * reaches Code Insights through with no credential.
     */
    public const array CLIENT = ['proxy' => 'http://localhost:29418'];

    /** Bitbucket Cloud's API as the proxy takes it: over plain HTTP, which the proxy carries on over TLS. */
    private const string API = 'http://api.bitbucket.org/2.0';

    private const string REPORTS = '/repositories/%s/%s/commit/%s/reports/%s';

    private const string ANNOTATIONS = '%s/annotations';

    /** The most annotations a report holds. */
    private const int MOST = 1_000;

    /** The most annotations one request posts. */
    private const int BATCH = 100;

    private const string NOT_IN_PIPELINES
        = 'This run is no Bitbucket Pipelines step, so no Code Insights report is written.';

    private const string UNWRITTEN = 'The Code Insights report could not be written (%s).';

    private const string WRITTEN = 'the Code Insights report of commit %s';

    private function __construct(private RestApi $api, private string $report, private CiRun|CannotTell $run)
    {
    }

    /** The report of the commit the step these variables describe builds, written through this exchange. */
    public static function inEnvironment(Variables $environment, Exchange $exchange): self
    {
        $commit = $environment->valueOf('BITBUCKET_COMMIT');

        return new self(
            RestApi::at($exchange, self::API, NotGiven::value()),
            sprintf(
                self::REPORTS,
                rawurlencode($environment->valueOf('BITBUCKET_WORKSPACE')),
                rawurlencode($environment->valueOf('BITBUCKET_REPO_SLUG')),
                rawurlencode($commit),
                ThisPackage::NAME,
            ),
            $commit === '' ? CannotTell::because(self::NOT_IN_PIPELINES) : CiRun::read($environment),
        );
    }

    public function report(Verdict $verdict): Written|NotWritten
    {
        $run = $this->run;

        if ($run instanceof CannotTell) {
            return NotWritten::because($run->why());
        }

        $deleted = $this->api->delete($this->report);
        $cleared = $deleted instanceof Reply && $deleted->isMissing()
            ? $deleted
            : $this->api->json($deleted);
        $written = $cleared instanceof CannotJudge ? $cleared : $this->api->json(
            $this->api->put($this->report, $this->document($verdict, $run)),
        );

        return $written instanceof CannotJudge
            ? NotWritten::because(sprintf(self::UNWRITTEN, $written->why()))
            : $this->annotated($verdict, Written::to(sprintf(self::WRITTEN, $run->commit())));
    }

    /** The report's annotations, posted a batch at a time; the first batch that is not written says why. */
    private function annotated(Verdict $verdict, Written $report): Written|NotWritten
    {
        $order = AnnotationOrder::of($verdict);
        $gate = Overview::of($verdict)->gate();
        $annotations = [];

        foreach ($order->failing() as $item) {
            $annotations[] = InsightAnnotation::of($item, failing: true, gate: $gate);
        }

        foreach ($order->other() as $item) {
            $annotations[] = InsightAnnotation::of($item, failing: false, gate: $gate);
        }

        $outcome = $report;

        foreach (array_chunk(array_slice($annotations, 0, self::MOST), self::BATCH) as $batch) {
            $posted = $outcome instanceof Written
                ? $this->api->json($this->api->post(sprintf(self::ANNOTATIONS, $this->report), Json::items(...$batch)))
                : $outcome;
            $outcome = $posted instanceof CannotJudge
                ? NotWritten::because(sprintf(self::UNWRITTEN, $posted->why()))
                : $outcome;
        }

        return $outcome;
    }

    /** The report itself: its verdict, the project's score, how many mutants are not killed, and the run. */
    private function document(Verdict $verdict, CiRun $run): Json
    {
        $overview = Overview::of($verdict);
        $score = $overview->score();

        $data = [
            ...$score instanceof Score ? [$this->datum('Score', 'PERCENTAGE', $score->percent())] : [],
            $this->datum('Not killed', 'NUMBER', count($overview->survivors())),
        ];
        return Json::object(
            Member::of('title', ThisPackage::NAME),
            Member::of('details', SetText::project($score)),
            Member::of('report_type', 'BUG'),
            Member::of('reporter', ThisPackage::NAME),
            Member::of('link', $run->url()),
            Member::of('result', $verdict->judgement() === Judgement::Passed ? 'PASSED' : 'FAILED'),
            Member::of('data', Json::items(...$data)),
        );
    }

    /** One field of the report's data: its title, its type, and its value. */
    private function datum(string $title, string $type, int|float $value): Json
    {
        return Json::object(Member::of('title', $title), Member::of('type', $type), Member::of('value', $value));
    }
}
