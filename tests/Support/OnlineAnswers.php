<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Cli\Doctor\Online;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Doctor\GitHub\GitHubSettings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\ProjectFiles;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use Symfony\Component\HttpClient\MockHttpClient;

/** What `doctor --online` finds of GitHub, as its tests ask it. */
final readonly class OnlineAnswers
{
    /**
     * What `--online` finds, in a project with these environment variables, asking this GitHub.
     *
     * @param array<string, string> $environment
     */
    public static function found(string $project, array $environment, MockHttpClient $github): GitHubSettings|CannotTell|NotGiven
    {
        return new Online($project, Variables::of($environment), $github)
            ->into(Observations::none()->withFiles(ProjectFiles::none()->withRunningTheGate(Paths::of(
                Path::of('.github/workflows/mutation.yml'),
                Path::of('.gitlab-ci.yml'),
            ))))
            ->asked()
            ->gitHub();
    }
}
