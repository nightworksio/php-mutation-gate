<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function implode;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Adapter\Filesystem\MeasuredCosts;
use NightWorksIO\MutationGate\Adapter\Filesystem\Resources;
use NightWorksIO\MutationGate\Adapter\Git\Git;
use NightWorksIO\MutationGate\Cli\Config\Chosen;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\CiTemplate;
use NightWorksIO\MutationGate\Core\Ci\DefaultBranch;
use NightWorksIO\MutationGate\Core\Ci\GatePin;
use NightWorksIO\MutationGate\Core\Ci\GitHubWorkflow;
use NightWorksIO\MutationGate\Core\Ci\PhpVersion;
use NightWorksIO\MutationGate\Core\Ci\Printed;
use NightWorksIO\MutationGate\Core\Ci\TemplateValues;
use NightWorksIO\MutationGate\Core\Composer\Manifest;
use NightWorksIO\MutationGate\Core\Config\BuiltinCiPlan;
use NightWorksIO\MutationGate\Core\Config\Ci;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Cost\FirstRun;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\Timings;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Extension\Extensions;

use function sprintf;

/**
 * `init --ci`: the CI definition that runs the gate (ADR-0015 decisions 13
 * to 16). Each of the CI's templates, from the package's `resources/ci/`, is
 * filled in with what the project shows: the PHP version, the default
 * branch, the runner and the gate's own release. It is written where no file
 * is, printed for a file the CI already reads, which `init` never edits, or,
 * with `--stdout`, printed whole. On GitHub, a full run estimated to fit one
 * shard gets the one-step action, and a larger one the reusable workflow.
 */
final readonly class CiDefinition
{
    private const string ALREADY_HERE
        = '%s is already here, and init --ci writes a definition only where there is none. --stdout prints it instead.';

    private const string ESTIMATED = '%s: a full run is estimated at %s from its lines of code, %s shards.seconds (%s)';

    private const string NOT_ESTIMATED = '%s, since a full run cannot be estimated: %s';

    private const string ASKED = '%s, as --%s asks';

    private const string GATE_UNPINNED
        = 'Composer did not install the gate here, so the definition names %s: pin the commit of a release.';

    private const string MISSING = 'The template %s is missing from the package. Reinstall it.';

    private const string UNNAMED = 'Set %s to %s in the config: reach and the proof key read it.';

    /** @param Directory $templates where each template is, by its file under it */
    public function __construct(
        private string $project,
        private Extensions $extensions,
        private GatePin $gate,
        private Directory $templates,
    ) {
    }

    /** From the templates the package ships under `resources/ci/`. */
    public static function packaged(string $project, Extensions $extensions, GatePin $gate): self
    {
        return new self($project, $extensions, $gate, Directory::at(Resources::at('ci')));
    }

    /**
     * The definition asked for, checked before anything is written: that each value can go into a template, and
     * that no file it would write is here, since `init` never replaces one. `$kept` says the config was here
     * already, so `init` writes none that could name the definition.
     */
    public function prepared(CiRequest $request, Settings $settings, bool $kept): PreparedCi|CannotJudge
    {
        $plan = $request->plan();
        $shard = $settings->shards()->seconds();
        $estimate = $plan === BuiltinCiPlan::GitHub && ! $request->workflow() instanceof GitHubWorkflow
            ? $this->estimate($settings)
            : CannotJudge::because('it is not estimated');
        $workflow = $this->workflow($request->workflow(), $estimate, $shard);
        $templates = CiTemplate::for($plan, $workflow);
        $values = $this->values($settings, $plan);
        $clash = $request->output() === Output::Written ? $this->clash($templates, $settings->ci()) : NotGiven::value();
        $why = $this->why($workflow, $request->workflow(), $estimate, $shard);

        return match (true) {
            $values instanceof CannotJudge => $values,
            $clash instanceof CannotJudge => $clash,
            default => PreparedCi::of(
                $templates,
                $values,
                $request->output(),
                Listed::of(...$this->notes($plan, $why, $settings, $kept)),
                $this->config($plan, $settings),
            ),
        };
    }

    /** What it wrote and printed, said; or why the first that could not be was not. */
    public function made(PreparedCi $prepared, Ci $ci): string|CannotJudge
    {
        $said = [];

        foreach ($prepared->templates() as $template) {
            $one = $this->one($template, $prepared->values(), $prepared->output(), $ci);

            if ($one instanceof CannotJudge) {
                return $one;
            }

            $said[] = $one;
        }

        return implode("\n", [...$said, ...$prepared->notes()]);
    }

    /**
     * A file these templates would write that is already here; nothing where none is.
     *
     * @param Listed<CiTemplate> $templates
     */
    private function clash(Listed $templates, Ci $ci): CannotJudge|NotGiven
    {
        foreach ($templates as $template) {
            $file = $template->destination($ci);

            if ($file instanceof Path && ! $this->project()->read($file) instanceof Missing) {
                return CannotJudge::because(sprintf(self::ALREADY_HERE, $file->value()));
            }
        }

        return NotGiven::value();
    }

    /** One template, written, printed for a file the CI reads, or printed whole; said, or why it was not. */
    private function one(
        CiTemplate $template,
        TemplateValues $values,
        Output $output,
        Ci $ci,
    ): string|CannotJudge {
        $read = $this->templates->read(Path::of($template->value));

        if (! $read instanceof Contents) {
            return $read instanceof CannotJudge
                ? $read
                : CannotJudge::because(sprintf(self::MISSING, $template->value));
        }

        $text = $values->rendered($read->text());
        $destination = $template->destination($ci);

        return match (true) {
            $destination instanceof Printed => sprintf("Add this to %s:\n\n%s", $destination->file(), $text),
            $output === Output::Printed => sprintf("%s:\n\n%s", $destination->value(), $text),
            default => $this->written($destination, $text),
        };
    }

    private function written(Path $file, string $text): string|CannotJudge
    {
        $written = $this->project()->write($file, Contents::of($text));

        return $written instanceof CannotJudge ? $written : sprintf('Wrote %s.', $file->value());
    }

    /**
     * What `init` adds to a config it writes for this CI (ADR-0024 decision 2): for Buildkite and Azure DevOps,
     * the file of the gate's jobs it writes, as the definition; for Azure DevOps, which names no default branch,
     * the one the project has.
     */
    private function config(BuiltinCiPlan $plan, Settings $settings): Layer
    {
        return match ($plan) {
            BuiltinCiPlan::Buildkite => Layer::of(Ci::of(buildkiteDefinition: CiTemplate::buildkitePipeline())),
            BuiltinCiPlan::Azure => Layer::of(Ci::of(
                defaultBranch: $this->defaultBranch($settings),
                azureDefinition: CiTemplate::azureJobs(),
            )),
            BuiltinCiPlan::GitHub, BuiltinCiPlan::GitLab, BuiltinCiPlan::CircleCi, BuiltinCiPlan::Json => Layer::none(),
        };
    }

    /** What the templates are filled in with, from the config, the project's files and git; or why it cannot be. */
    private function values(Settings $settings, BuiltinCiPlan $plan): TemplateValues|CannotJudge
    {
        $manifest = $this->project()->read(Manifest::fileIn(Path::root()));

        return TemplateValues::of(
            PhpVersion::in(Node::decode($manifest instanceof Contents ? $manifest->text() : '{}')),
            $this->defaultBranch($settings),
            $this->gate,
            $settings->runner()->choice()->use()->value(),
            $settings->ci()->check(),
            CiTemplate::included($plan, $settings->ci()),
        );
    }

    /**
     * What else the person needs to know: on GitHub, whose definition names the gate's commit, which definition
     * was written and why, the check to require, and that the commit is not known; on Buildkite and Azure DevOps,
     * with the config kept, that it must name the file of the gate's jobs written as the one that runs the gate.
     *
     * @return list<string>
     */
    private function notes(BuiltinCiPlan $plan, string $why, Settings $settings, bool $kept): array
    {
        $github = [
            sprintf('It is %s.', $why),
            sprintf(
                'Require the check `%s` in the protection of %s.',
                $settings->ci()->check(),
                $this->defaultBranch($settings),
            ),
            ...$this->gate->isKnown() ? [] : [sprintf(self::GATE_UNPINNED, $this->gate->commit())],
        ];
        $ci = $settings->ci();
        $unnamed = match ($plan) {
            BuiltinCiPlan::Buildkite => $this->unnamed(
                'ci.buildkite.definition',
                $ci->buildkiteDefinition(),
                CiTemplate::buildkitePipeline(),
            ),
            BuiltinCiPlan::Azure => $this->unnamed(
                'ci.azure.definition',
                $ci->azureDefinition(),
                CiTemplate::azureJobs(),
            ),
            BuiltinCiPlan::GitHub, BuiltinCiPlan::GitLab, BuiltinCiPlan::CircleCi, BuiltinCiPlan::Json => [],
        };

        return match (true) {
            $plan === BuiltinCiPlan::GitHub => $github,
            $kept => $unnamed,
            default => [],
        };
    }

    /**
     * That a config kept here must name the file of the gate's jobs `init` writes, under this key, where it names
     * another; nothing where it names that file.
     *
     * @return list<string>
     */
    private function unnamed(string $key, Path $named, Path $written): array
    {
        return $named->equals($written) ? [] : [sprintf(self::UNNAMED, $key, $written->value())];
    }

    /** The GitHub definition: the one asked for, or the one a full run's estimate fits, or else the one-step action. */
    private function workflow(
        GitHubWorkflow|NotGiven $asked,
        Seconds|CannotJudge $estimate,
        Seconds $shard,
    ): GitHubWorkflow {
        return match (true) {
            $asked instanceof GitHubWorkflow => $asked,
            $estimate instanceof Seconds => GitHubWorkflow::forEstimate($estimate, $shard),
            default => GitHubWorkflow::Single,
        };
    }

    /** Why the GitHub definition is the one it is, as a phrase. */
    private function why(
        GitHubWorkflow $workflow,
        GitHubWorkflow|NotGiven $asked,
        Seconds|CannotJudge $estimate,
        Seconds $shard,
    ): string {
        return match (true) {
            $asked instanceof GitHubWorkflow => sprintf(self::ASKED, $workflow->said(), $asked->value),
            $estimate instanceof Seconds => sprintf(
                self::ESTIMATED,
                $workflow->said(),
                $estimate->text(),
                $workflow === GitHubWorkflow::Single ? 'within' : 'more than',
                $shard->text(),
            ),
            default => sprintf(self::NOT_ESTIMATED, $workflow->said(), $estimate->why()),
        };
    }

    /** What a full run is estimated to take, from each tree's lines of code, or why it cannot be. */
    private function estimate(Settings $settings): Seconds|CannotJudge
    {
        $source = new Chosen($this->extensions)->treeSource($settings->treeSource());
        $trees = match (true) {
            $source instanceof Invalid => CannotJudge::because('the tree source cannot be built from its options'),
            $source instanceof CannotJudge => $source,
            default => $source->trees(),
        };

        if (! $trees instanceof Trees) {
            return $trees;
        }

        $costs = MeasuredCosts::at(Root::of($this->project), $settings->shards()->secondsPerLine());
        $seconds = 0.0;

        foreach ($trees as $tree) {
            $estimated = $costs->cost(Unit::file($tree->path()), Timings::none(), FirstRun::unmeasured());
            $seconds += $estimated->seconds()->seconds();
        }

        return Seconds::of($seconds);
    }

    private function defaultBranch(Settings $settings): string
    {
        return DefaultBranch::of($settings->ci()->defaultBranch(), Git::at($this->project)->defaultBranch())->name();
    }

    private function project(): Directory
    {
        return Directory::at($this->project);
    }
}
