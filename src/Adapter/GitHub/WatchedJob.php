<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use function count;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A job of the run a shard watches, as the reusable workflow's `watch-job`
 * names it (ADR-0135): once it concluded in failure, the shard starts no
 * further item. Read through the REST API with the job's own token, which
 * the action hands the gate as it hands the artifact service; a run that
 * watches no job, or cannot read the jobs, never stops for it.
 *
 * @internal
 */
final readonly class WatchedJob
{
    /** The variable of the gate's step that names the watched job. */
    public const string NAME = 'MUTATION_GATE_WATCH_JOB';

    private const string JOBS = '/repos/%s/actions/runs/%s/attempts/%s/jobs?per_page=100&page=%d';

    private const int PAGE = 100;

    private const int MOST_PAGES = 10;

    private const string FAILURE = 'failure';

    private const string STOPPED = 'The job %s failed, so this shard starts no further item.';

    private function __construct(
        private Api|NotGiven $api,
        private string $name,
        private string $run,
        private string $attempt,
        private string $repository,
    ) {
    }

    /** The job the environment names, read with the token the gate was handed; none where either is missing. */
    public static function of(
        Variables $environment,
        ArtifactService|CannotJudge|NotGiven $service,
        HttpClientInterface $client,
    ): self {
        $name = $environment->valueOf(self::NAME);
        $token = $service instanceof ArtifactService ? $service->github() : '';

        return new self(
            $name === '' || $token === ''
                ? NotGiven::value()
                : Api::at($client, $environment->valueOf('GITHUB_API_URL'), $token),
            $name,
            $environment->valueOf('GITHUB_RUN_ID'),
            $environment->valueOf('GITHUB_RUN_ATTEMPT'),
            $environment->valueOf('GITHUB_REPOSITORY'),
        );
    }

    /** Why the shard stops: the watched job concluded in failure; none where it did not, or cannot be read. */
    public function stopped(): string
    {
        if (! $this->api instanceof Api) {
            return '';
        }

        for ($page = 1; $page <= self::MOST_PAGES; $page++) {
            $answer = $this->api->get(sprintf(self::JOBS, $this->repository, $this->run, $this->attempt, $page));
            $jobs = $answer instanceof CannotTell ? [] : $answer->items('jobs');

            foreach ($jobs as $job) {
                if ($job->text('name') === $this->name && $job->text('conclusion') === self::FAILURE) {
                    return sprintf(self::STOPPED, $this->name);
                }
            }

            if (count($jobs) < self::PAGE) {
                return '';
            }
        }

        return '';
    }
}
