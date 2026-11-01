<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Bitbucket;

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
 * The reporter `bitbucket-comment`: one sticky comment on the pull request, updated in place on every run through
 * Bitbucket Cloud's pull request comments API (ADR-0024, ADR-0048). The token is read from the variable
 * `tokenEnv` names, `MUTATION_GATE_BITBUCKET_TOKEN` by default: a repository access token with
 * `pullrequest:write` alone. A pull request from a fork starts no pipeline, so every run that comments is the
 * repository's own; one that cannot comment says why and fails nothing.
 */
final readonly class PullRequestComment implements Commenting, Deferring, Reporter
{
    /** Bitbucket Cloud's API. */
    public const string API = 'https://api.bitbucket.org/2.0';

    private const string NO_TOKEN = '%s is not set, so no comment is written.';

    private function __construct(private CommentStates $states)
    {
    }

    /**
     * The comment of the pull request the step these variables describe runs for, written with the token in this
     * variable; one that says why it cannot be written where they describe none.
     */
    public static function inEnvironment(Variables $environment, Exchange $exchange, string $tokenEnv): self
    {
        $number = PullRequestNumber::parse($environment->valueOf(Variables::BITBUCKET_PR_ID));
        $token = $environment->valueOf($tokenEnv);
        $run = CiRun::read($environment);
        $pipeline = $run instanceof CannotTell ? '' : $run->url();
        $thread = PullRequestThread::of(
            RestApi::at($exchange, self::API, Token::bearer($token)),
            $environment->valueOf('BITBUCKET_WORKSPACE'),
            $environment->valueOf('BITBUCKET_REPO_SLUG'),
            match (true) {
                ! $number instanceof PullRequestNumber => NotWritten::because(CommentStates::NOT_A_PULL_REQUEST),
                $token === '' => NotWritten::because(sprintf(self::NO_TOKEN, $tokenEnv)),
                default => $number,
            },
            $pipeline,
        );

        return new self(CommentStates::in($thread, $pipeline, commentsHere: $number instanceof PullRequestNumber));
    }

    /** From the step's own variables, reading the token from the variable `tokenEnv` names. */
    public static function configured(Options $options, Variables $environment, Exchange $exchange): self
    {
        return self::inEnvironment(
            $environment,
            $exchange,
            CommentOption::tokenEnv($options, GateSecret::BitbucketToken->value),
        );
    }

    public function report(Verdict $verdict): Written|NotWritten
    {
        return $this->states->judged($verdict);
    }

    /** A delivery, with the comment's markdown, where the run is a pull request's, for `deliver` (ADR-0007). */
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
}
