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

    /** What `init` adds to a config it writes for this CI: for Buildkite, the pipeline it writes, as its definition. */
    public static function configOf(CiRequest|NotGiven $request): Layer
    {
        return $request instanceof CiRequest && $request->plan() === BuiltinCiPlan::Buildkite
            ? Layer::of(Ci::of(buildkiteDefinition: Path::of(CiTemplate::BUILDKITE_PIPELINE)))
            : Layer::none();
    }

    /** A file it would write that is already here, which `init` never replaces; nothing where none is. */
    public function clash(CiRequest $request, Settings $settings): CannotJudge|NotGiven
    {
        $templates = $request->output() === Output::Written
            ? CiTemplate::for($request->plan(), GitHubWorkflow::Single)
            : Listed::of();

        foreach ($templates instanceof Listed ? $templates : [] as $template) {
            $file = $template->destination($settings->ci());

            if ($file instanceof Path && ! $this->project()->read($file) instanceof Missing) {
                return CannotJudge::because(sprintf(self::ALREADY_HERE, $file->value()));
            }
        }

        return NotGiven::value();
    }

    /** What it wrote and printed, said; or why nothing could be. */
    public function made(CiRequest $request, Settings $settings): string|CannotJudge
    {
        $plan = $request->plan();
        $estimate = $plan === BuiltinCiPlan::GitHub && ! $request->workflow() instanceof GitHubWorkflow
            ? $this->estimate($settings)
            : CannotJudge::because('it is not estimated');
        $workflow = $this->workflow($request->workflow(), $estimate, $settings->shards()->seconds());
        $templates = CiTemplate::for($plan, $workflow);
        $said = $templates instanceof CannotJudge ? $templates : $this->each($templates, $request->output(), $settings);
        $why = $this->why($workflow, $request->workflow(), $estimate, $settings->shards()->seconds());

        return $said instanceof CannotJudge
            ? $said
            : implode("\n", [...$said, ...$this->notes($plan, $workflow, $why, $settings)]);
    }

    /**
     * Each template, made, said; or why the first that could not be was not.
     *
     * @param  Listed<CiTemplate>        $templates
     * @return list<string>|CannotJudge
     */
    private function each(Listed $templates, Output $output, Settings $settings): array|CannotJudge
    {
        $values = $this->values($settings);
        $said = [];

        foreach ($templates as $template) {
            $one = $this->one($template, $values, $output, $settings->ci());

            if ($one instanceof CannotJudge) {
                return $one;
            }

            $said[] = $one;
        }

        return $said;
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

    /** What the templates are filled in with, from the config, the project's files and git. */
    private function values(Settings $settings): TemplateValues
    {
        $manifest = $this->project()->read(Manifest::fileIn(Path::root()));

        return TemplateValues::of(
            PhpVersion::in(Node::decode($manifest instanceof Contents ? $manifest->text() : '{}')),
            $this->defaultBranch($settings),
            $this->gate,
            $settings->runner()->choice()->use()->value(),
            $settings->ci()->gitlabTemplate()->value(),
        );
    }

    /**
     * What else the person needs to know on GitHub, whose definition names the gate's commit: which definition
     * was written and why, the check to require, and that the commit is not known.
     *
     * @return list<string>
     */
    private function notes(BuiltinCiPlan $plan, GitHubWorkflow $workflow, string $why, Settings $settings): array
    {
        $notes = $plan === BuiltinCiPlan::GitHub ? [
            sprintf('It is %s.', $why),
            sprintf(
                'Require the check `%s` in the protection of %s.',
                $workflow->check(),
                $this->defaultBranch($settings),
            ),
        ] : [];

        return $this->gate->isKnown() || $notes === []
            ? $notes
            : [...$notes, sprintf(self::GATE_UNPINNED, $this->gate->commit())];
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
            $seconds += $costs->cost(Unit::file($tree->path()), Timings::none())->seconds();
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
