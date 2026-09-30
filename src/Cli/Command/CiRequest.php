<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function array_map;
use function count;
use function file_exists;
use function implode;
use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\CiTemplate;
use NightWorksIO\MutationGate\Core\Ci\Definitions;
use NightWorksIO\MutationGate\Core\Ci\GitHubWorkflow;
use NightWorksIO\MutationGate\Core\Config\BuiltinCiPlan;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * What `init --ci` is asked for (ADR-0015 decision 13, ADR-0017 decision 1):
 * the CI named, or with `--ci` alone the one the project's files show; for
 * GitHub, the one-step action or the reusable workflow where `--single` or
 * `--sharded` picks one; and with `--stdout`, the files printed rather than
 * written.
 */
final readonly class CiRequest
{
    private const string CI = 'ci';

    private const string NONE_SHOWN = 'No CI is detected here, so init writes nothing. Name one with --ci=<name>.';

    private const string SEVERAL_SHOWN = '%s are all detected here, so init writes nothing. Name one with --ci=<name>.';

    private const string GITHUB_ONLY = '--%s chooses GitHub\'s definition, so it takes --ci=github.';

    private function __construct(
        private BuiltinCiPlan $plan,
        private GitHubWorkflow|NotGiven $workflow,
        private Output $output,
    ) {
    }

    public static function of(BuiltinCiPlan $plan, GitHubWorkflow|NotGiven $workflow, Output $output): self
    {
        return new self($plan, $workflow, $output);
    }

    public static function options(Command $command): Command
    {
        return $command
            ->addOption(
                GitHubWorkflow::Sharded->value,
                mode: InputOption::VALUE_NONE,
                description: 'On GitHub, the reusable workflow',
            )
            ->addOption(
                GitHubWorkflow::Single->value,
                mode: InputOption::VALUE_NONE,
                description: 'On GitHub, the one-step action',
            );
    }

    /** What the command line asks for, where it asks for a CI definition; why it cannot be written, where not. */
    public static function from(InputInterface $input, string $project): self|NotGiven|CannotJudge
    {
        $named = $input->getOption(self::CI);

        if ($named === false) {
            return NotGiven::value();
        }

        $workflow = self::asked($input);
        $plan = is_string($named) ? self::named($named) : self::detected($project);

        return match (true) {
            $workflow instanceof CannotJudge => $workflow,
            $plan instanceof CannotJudge => $plan,
            $plan !== BuiltinCiPlan::GitHub && $workflow instanceof GitHubWorkflow => CannotJudge::because(
                sprintf(self::GITHUB_ONLY, $workflow->value),
            ),
            default => self::of($plan, $workflow, Output::asked($input)),
        };
    }

    /** The CI to write a definition for. */
    public function plan(): BuiltinCiPlan
    {
        return $this->plan;
    }

    /** The GitHub definition `--single` or `--sharded` picks, or none, for the estimate to pick. */
    public function workflow(): GitHubWorkflow|NotGiven
    {
        return $this->workflow;
    }

    public function output(): Output
    {
        return $this->output;
    }

    /** The CI a name names, where `init --ci` writes a definition for it; why not, where not. */
    private static function named(string $named): BuiltinCiPlan|CannotJudge
    {
        $plan = BuiltinCiPlan::tryFrom($named);

        if (! $plan instanceof BuiltinCiPlan) {
            return CiTemplate::none($named);
        }

        $templates = CiTemplate::for($plan, GitHubWorkflow::Single);

        return $templates instanceof CannotJudge ? $templates : $plan;
    }

    private static function asked(InputInterface $input): GitHubWorkflow|NotGiven|CannotJudge
    {
        $sharded = $input->getOption(GitHubWorkflow::Sharded->value) === true;
        $single = $input->getOption(GitHubWorkflow::Single->value) === true;

        return match (true) {
            $sharded && $single => CannotJudge::because('--sharded and --single each choose one; pass one of them.'),
            $sharded => GitHubWorkflow::Sharded,
            $single => GitHubWorkflow::Single,
            default => NotGiven::value(),
        };
    }

    /** The one CI the project's files show, or why there is none to write for. */
    private static function detected(string $project): BuiltinCiPlan|CannotJudge
    {
        $shown = [];

        foreach (BuiltinCiPlan::cases() as $plan) {
            $place = Definitions::shownBy($plan);
            $there = $place instanceof Path && file_exists(Root::of($project)->at($place)->value());
            $shown = $there ? [...$shown, $plan] : $shown;
        }

        return match (count($shown)) {
            0 => CannotJudge::because(self::NONE_SHOWN),
            1 => $shown[0],
            default => CannotJudge::because(sprintf(
                self::SEVERAL_SHOWN,
                implode(', ', array_map(static fn(BuiltinCiPlan $plan): string => $plan->value, $shown)),
            )),
        };
    }
}
