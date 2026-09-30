<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Doctor;

use function is_string;

use NightWorksIO\MutationGate\Adapter\Git\Git;
use NightWorksIO\MutationGate\Adapter\GitHub\Api;
use NightWorksIO\MutationGate\Adapter\GitHub\RepositoryName;
use NightWorksIO\MutationGate\Adapter\GitHub\RepositorySettings;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

use function parse_url;

use const PHP_URL_HOST;

use function sprintf;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * What `doctor --online` adds by asking GitHub (ADR-0017, decision 9). The
 * repository is the one `GITHUB_REPOSITORY` names, else the one git's origin
 * remote is on GitHub's host, `GITHUB_SERVER_URL`'s or github.com. It asks
 * with `GITHUB_TOKEN`, else `GH_TOKEN`, at `GITHUB_API_URL` or GitHub's own
 * API, about the workflows under `.github/workflows` that run the gate.
 */
final readonly class Online
{
    private const string GITHUB = 'github.com';

    private const string WORKFLOWS = '.github/workflows';

    private const string NO_REMOTE = 'GITHUB_REPOSITORY is not set, and git names no origin remote: %s';

    public function __construct(
        private string $project,
        private Variables $environment,
        private HttpClientInterface $client,
    ) {
    }

    /** These observations, with the repository's settings on GitHub, or why no repository there was found. */
    public function into(Observations $observed): Observations
    {
        $repository = $this->repository();
        $settings = $repository instanceof RepositoryName
            ? RepositorySettings::of($this->api(), $repository)->read($this->workflows($observed))
            : $repository;

        return $observed->withAsked($observed->asked()->withGitHub($settings));
    }

    private function repository(): RepositoryName|CannotTell
    {
        $named = $this->environment->valueOf('GITHUB_REPOSITORY');

        if ($named !== '') {
            return RepositoryName::of($named);
        }

        $url = Git::at($this->project)->originUrl();

        return is_string($url)
            ? RepositoryName::fromRemote($url, $this->host())
            : CannotTell::because(sprintf(self::NO_REMOTE, $url->why()));
    }

    private function host(): string
    {
        $host = parse_url($this->environment->valueOf('GITHUB_SERVER_URL'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : self::GITHUB;
    }

    private function api(): Api
    {
        $token = $this->environment->valueOf('GITHUB_TOKEN');

        return Api::at(
            $this->client,
            $this->environment->valueOf('GITHUB_API_URL'),
            $token === '' ? $this->environment->valueOf('GH_TOKEN') : $token,
        );
    }

    /** The GitHub workflows among the CI definitions that run the gate. */
    private function workflows(Observations $observed): Paths
    {
        $running = $observed->files()->runningTheGate();
        $workflows = Paths::none();

        foreach ($running instanceof Paths ? $running : [] as $definition) {
            $workflows = $definition->within(Path::of(self::WORKFLOWS)) ? $workflows->with($definition) : $workflows;
        }

        return $workflows;
    }
}
