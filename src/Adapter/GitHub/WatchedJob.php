<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use function count;
use function explode;
use function in_array;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;

use Symfony\Contracts\HttpClient\HttpClientInterface;

use function trim;

/**
 * The jobs of the run a shard watches, as the reusable workflow's
 * `watch-jobs` names them, one per line (ADR-0135): once one concluded in
 * failure, the shard starts no further item. Read through the REST API with
 * the job's own token, which the action hands the gate as it hands the
 * artifact service; a run that watches no job, or cannot read the jobs,
 * never stops for them.
 *
 * @internal
 */
final readonly class WatchedJob
{
    /** The variable of the gate's step that names the watched jobs, one per line. */
    public const string NAME = 'MUTATION_GATE_WATCH_JOBS';

    private const string JOBS = '/repos/%s/actions/runs/%s/attempts/%s/jobs?per_page=100&page=%d';

    private const int PAGE = 100;

    private const int MOST_PAGES = 10;

    private const string FAILURE = 'failure';

    private const string STOPPED = 'The job %s failed, so this shard starts no further item.';

    /** @param list<string> $names */
    private function __construct(
        private Api|NotGiven $api,
        private array $names,
        private string $run,
        private string $attempt,
        private string $repository,
    ) {
    }

    /** The jobs the environment names, read with the token the gate was handed; none where either is missing. */
    public static function of(
        Variables $environment,
        ArtifactService|CannotJudge|NotGiven $service,
        HttpClientInterface $client,
    ): self {
        $names = [];

        foreach (explode("\n", $environment->valueOf(self::NAME)) as $name) {
            if (trim($name) !== '') {
                $names[] = trim($name);
            }
        }

        $token = $service instanceof ArtifactService ? $service->github() : '';

        return new self(
            $names === [] || $token === ''
                ? NotGiven::value()
                : Api::at($client, $environment->valueOf('GITHUB_API_URL'), $token),
            $names,
            $environment->valueOf('GITHUB_RUN_ID'),
            $environment->valueOf('GITHUB_RUN_ATTEMPT'),
            $environment->valueOf('GITHUB_REPOSITORY'),
        );
    }

    /** Why the shard stops: a watched job concluded in failure; none where none did, or they cannot be read. */
    public function stopped(): string
    {
        if (! $this->api instanceof Api) {
            return '';
        }

        for ($page = 1; $page <= self::MOST_PAGES; $page++) {
            $answer = $this->api->get(sprintf(self::JOBS, $this->repository, $this->run, $this->attempt, $page));
            $jobs = $answer instanceof CannotTell ? [] : $answer->items('jobs');

            foreach ($jobs as $job) {
                if (in_array($job->text('name'), $this->names, true) && $job->text('conclusion') === self::FAILURE) {
                    return sprintf(self::STOPPED, $job->text('name'));
                }
            }

            if (count($jobs) < self::PAGE) {
                return '';
            }
        }

        return '';
    }
}
