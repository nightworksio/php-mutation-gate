<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Azure;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\CiRun;
use NightWorksIO\MutationGate\Core\Ci\PullRequestNumber;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Comment\Toggle;
use NightWorksIO\MutationGate\Core\Config\CommentOption;
use NightWorksIO\MutationGate\Core\Config\CommentSettings;
use NightWorksIO\MutationGate\Core\Config\Definition\CommentKeys;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Delivery\Commenting;
use NightWorksIO\MutationGate\Core\Delivery\CommentStates;
use NightWorksIO\MutationGate\Core\Delivery\CommentWrite;
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

use function rawurlencode;
use function rtrim;
use function sprintf;

/**
 * The reporter `azure-comment`: one comment on the pull request, in a thread of its own, updated in place on every run
 * through Azure Repos' REST API. The token is read from the variable `tokenEnv` names, `SYSTEM_ACCESSTOKEN` by default,
 * which a pipeline maps from `$(System.AccessToken)`. A run that cannot comment says why and fails nothing.
 *
 * @internal
 */
final readonly class PullRequestComment implements Commenting, Deferring, Reporter
{
    private const string FORK
        = 'A fork\'s pull request build holds no token that can comment, so no comment is written.';

    private const string PAGE = '%s/%s/_git/%s/pullrequest/%d';

    private function __construct(private CommentStates $states)
    {
    }

    /**
     * The comment of the pull request the job these variables describe builds, written with the token in this
     * variable, shaped as `comment` says; one that says why it cannot be written where they describe none.
     */
    public static function inEnvironment(
        Variables $environment,
        Exchange $exchange,
        string $tokenEnv,
        CommentSettings $settings,
    ): self {
        $number = PullRequestNumber::parse($environment->valueOf('SYSTEM_PULLREQUEST_PULLREQUESTID'));
        $token = $environment->valueOf($tokenEnv);
        $run = CiRun::read($environment);
        $pipeline = $run instanceof CannotTell ? '' : $run->url();
        $collection = rtrim($environment->valueOf('SYSTEM_COLLECTIONURI'), '/');
        $project = $environment->valueOf('SYSTEM_TEAMPROJECT');
        $thread = PullRequestThread::of(
            RestApi::at($exchange, $collection, Token::bearer($token)),
            $project,
            $environment->valueOf('BUILD_REPOSITORY_ID'),
            sprintf(
                self::PAGE,
                $collection,
                rawurlencode($project),
                rawurlencode($environment->valueOf('BUILD_REPOSITORY_NAME')),
                $number instanceof PullRequestNumber ? $number->value() : 0,
            ),
            match (true) {
                ! $number instanceof PullRequestNumber => NotWritten::because(CommentStates::NOT_A_PULL_REQUEST),
                $environment->valueOf('SYSTEM_PULLREQUEST_ISFORK') === 'True' => NotWritten::because(self::FORK),
                $token === '' => NotWritten::because(sprintf(CommentStates::NO_TOKEN, $tokenEnv)),
                default => $number,
            },
            $pipeline,
        );

        return new self(CommentStates::in(
            $thread,
            $pipeline,
            commentsHere: $number instanceof PullRequestNumber,
            settings: $settings,
            draft: ! $settings->writes()->allows(Toggle::Drafts) && $thread->isDraft(),
        ));
    }

    /** From the job's own variables, the token read from the variable `tokenEnv` names, shaped by `with.comment`. */
    public static function configured(Options $options, Variables $environment, Exchange $exchange): self
    {
        return self::inEnvironment(
            $environment,
            $exchange,
            CommentOption::tokenEnv($options, GateSecret::AzureComment->value),
            CommentKeys::settingsIn($options),
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

    public function writeAs(string $markdown, CommentWrite $write): Written|NotWritten
    {
        return $this->states->writeAs($markdown, $write);
    }
}
