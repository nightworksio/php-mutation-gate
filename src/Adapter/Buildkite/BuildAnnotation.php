<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Buildkite;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\CiRun;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Comment\Occasion;
use NightWorksIO\MutationGate\Core\Config\CommentSettings;
use NightWorksIO\MutationGate\Core\Config\CommentShows;
use NightWorksIO\MutationGate\Core\Config\Definition\CommentKeys;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Delivery\Commenting;
use NightWorksIO\MutationGate\Core\Delivery\CommentStates;
use NightWorksIO\MutationGate\Core\Delivery\CommentWrite;
use NightWorksIO\MutationGate\Core\Delivery\Delivery;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Plan\PlannedWork;
use NightWorksIO\MutationGate\Core\Recheck\Rechecked;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\Processes;
use NightWorksIO\MutationGate\Port\Reporter;

/**
 * The reporter `buildkite-annotation`: the build's one annotation, updated in place through the planned, re-checked and
 * verdict states, on every build `comment.on` lets. Pull request builds and others both write it unless `comment.on`
 * narrows them. Every section shows open unless `with.comment` opens fewer.
 *
 * @internal
 */
final readonly class BuildAnnotation implements Commenting, Reporter
{
    private const string NOT_A_PULL_REQUEST = 'false';

    private function __construct(private CommentStates $states)
    {
    }

    /** The annotation of the build these variables describe, shaped by `with.comment`, through these processes. */
    public static function configured(
        Options $options,
        Variables $environment,
        Processes $processes,
        string $directory,
    ): self {
        $run = CiRun::read($environment);
        $url = $run instanceof CannotTell ? '' : $run->url();
        $settings = CommentKeys::settingsIn($options);
        $pullRequest = $environment->valueOf('BUILDKITE_PULL_REQUEST');

        return new self(CommentStates::in(
            AnnotationThread::of($processes, $directory, $url, $environment->has(Variables::BUILDKITE)),
            $url,
            commentsHere: false,
            settings: CommentSettings::of(CommentShows::unfolded()->over($settings->shows()), $settings->writes()),
            occasion: $pullRequest === '' || $pullRequest === self::NOT_A_PULL_REQUEST
                ? Occasion::DefaultBranch
                : Occasion::PullRequest,
            standardOccasions: Listed::of(Occasion::PullRequest, Occasion::DefaultBranch),
        ));
    }

    public function report(Verdict $verdict): Written|NotWritten
    {
        return $this->states->judged($verdict);
    }

    public function planned(PlannedWork $work): Written|NotWritten
    {
        return $this->states->planned($work);
    }

    public function plannedLater(PlannedWork $work, Delivery $delivery): Delivery
    {
        return $this->states->plannedLater($work, $delivery);
    }

    public function rechecked(Rechecked $rechecked): Written|NotWritten
    {
        return $this->states->rechecked($rechecked);
    }

    public function recheckedLater(Rechecked $rechecked, Delivery $delivery): Delivery
    {
        return $this->states->recheckedLater($rechecked, $delivery);
    }

    public function writeAs(string $markdown, CommentWrite $write): Written|NotWritten
    {
        return $this->states->writeAs($markdown, $write);
    }
}
