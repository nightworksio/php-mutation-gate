<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitLab;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\CiRun;
use NightWorksIO\MutationGate\Core\Ci\PullRequestNumber;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\CommentOption;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Delivery\Commenting;
use NightWorksIO\MutationGate\Core\Delivery\CommentStates;
use NightWorksIO\MutationGate\Core\Delivery\Deferring;
use NightWorksIO\MutationGate\Core\Delivery\Delivery;
use NightWorksIO\MutationGate\Core\Http\Exchange;
use NightWorksIO\MutationGate\Core\Http\RestApi;
use NightWorksIO\MutationGate\Core\Http\Token;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Plan\PlannedWork;
use NightWorksIO\MutationGate\Core\Recheck\Rechecked;
use NightWorksIO\MutationGate\Core\Runner\GateSecret;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\Reporter;

use function sprintf;

/**
 * The reporter `gitlab-comment`: one sticky note on the merge request, found by its hidden marker among the notes
 * of the token's own user, which GitLab's `/user` names, and updated in place on every run through the notes API
 * (ADR-0024). The token is read from the variable `tokenEnv` names, `MUTATION_GATE_GITLAB_TOKEN` by default: a
 * project access token with the scope `api`. A fork's merge request pipeline runs in the fork, with none of the
 * project's variables, so it writes no note, and says why; so does any run that cannot write one, failing nothing.
 */
final readonly class MergeRequestNote implements Commenting, Deferring, Reporter
{
    /** GitLab.com's API, where `CI_API_V4_URL` names no other. */
    private const string GITLAB = 'https://gitlab.com/api/v4';

    private const string NOT_A_MERGE_REQUEST = 'This run is not for a merge request, so there is no note to write.';

    private const string NO_TOKEN = '%s is not set, so no note is written.';

    private const string FORK
        = 'A fork\'s merge request pipeline holds none of the project\'s variables, so no note is written.';

    private function __construct(private CommentStates $states)
    {
    }

    /**
     * The note of the merge request the job these variables describe runs for, written with the token in this
     * variable; one that says why it cannot be written where they describe none it may write.
     */
    public static function inEnvironment(Variables $environment, Exchange $exchange, string $tokenEnv): self
    {
        $number = self::numberIn($environment);
        $token = $environment->valueOf($tokenEnv);
        $target = match (true) {
            ! $number instanceof PullRequestNumber => NotWritten::because(self::NOT_A_MERGE_REQUEST),
            self::isFork($environment) => NotWritten::because(self::FORK),
            $token === '' => NotWritten::because(sprintf(self::NO_TOKEN, $tokenEnv)),
            default => $number,
        };
        $api = $environment->valueOf('CI_API_V4_URL');
        $run = CiRun::read($environment);
        $pipeline = $run instanceof CannotTell ? '' : $run->url();
        $thread = MergeRequestThread::of(
            RestApi::at($exchange, $api === '' ? self::GITLAB : $api, Token::bearer($token)),
            self::either($environment, 'CI_MERGE_REQUEST_PROJECT_ID', 'CI_PROJECT_ID'),
            self::either($environment, 'CI_MERGE_REQUEST_PROJECT_URL', 'CI_PROJECT_URL'),
            $target,
            $pipeline,
        );

        return new self(CommentStates::in($thread, $pipeline, commentsHere: $number instanceof PullRequestNumber));
    }

    /** From the job's own variables, reading the token from the variable `tokenEnv` names. */
    public static function configured(Options $options, Variables $environment, Exchange $exchange): self
    {
        return self::inEnvironment(
            $environment,
            $exchange,
            CommentOption::tokenEnv($options, GateSecret::GitLabComment->value),
        );
    }

    public function report(Verdict $verdict): Written|NotWritten
    {
        return $this->states->judged($verdict);
    }

    /** A delivery, with the note's markdown, where the run is a merge request's, for `deliver` to write (ADR-0007). */
    public function deferred(Verdict $verdict, Delivery $delivery): Delivery
    {
        return $this->states->judgedLater($verdict, $delivery);
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

    public function write(string $markdown): Written|NotWritten
    {
        return $this->states->write($markdown);
    }

    public function writeOverPlanned(string $markdown): Written|NotWritten
    {
        return $this->states->writeOverPlanned($markdown);
    }

    /**
     * The merge request's IID: GitLab's own, or, in a child pipeline, which GitLab gives none, the one the trigger
     * job hands it.
     */
    private static function numberIn(Variables $environment): PullRequestNumber|CannotTell
    {
        return PullRequestNumber::parse(self::either($environment, 'CI_MERGE_REQUEST_IID', GitLabPlan::MERGE_REQUEST));
    }

    /** Whether the merge request comes from another project than the one it targets. */
    private static function isFork(Variables $environment): bool
    {
        $source = $environment->valueOf('CI_MERGE_REQUEST_SOURCE_PROJECT_ID');

        return $source !== '' && $source !== $environment->valueOf('CI_MERGE_REQUEST_PROJECT_ID');
    }

    /** The first of these variables that holds a value; empty where none does. */
    private static function either(Variables $environment, string $first, string $second): string
    {
        $value = $environment->valueOf($first);

        return $value === '' ? $environment->valueOf($second) : $value;
    }
}
