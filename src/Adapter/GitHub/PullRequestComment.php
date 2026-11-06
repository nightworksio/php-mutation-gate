<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use function array_key_exists;
use function count;
use function file_get_contents;
use function getenv;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\PullRequestNumber;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\CommentSettings;
use NightWorksIO\MutationGate\Core\Config\Definition\CommentKeys;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Delivery\Commenting;
use NightWorksIO\MutationGate\Core\Delivery\CommentStates;
use NightWorksIO\MutationGate\Core\Delivery\CommentThread;
use NightWorksIO\MutationGate\Core\Delivery\CommentWrite;
use NightWorksIO\MutationGate\Core\Delivery\Deferring;
use NightWorksIO\MutationGate\Core\Delivery\Delivery;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Plan\PlannedWork;
use NightWorksIO\MutationGate\Core\Recheck\Rechecked;
use NightWorksIO\MutationGate\Core\Report\Markdown;
use NightWorksIO\MutationGate\Core\Report\OwnerMentions;
use NightWorksIO\MutationGate\Core\Report\PlannedMarkdown;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Configurable;
use NightWorksIO\MutationGate\Port\Reporter;

use function sprintf;
use function str_contains;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The reporter `github-comment`: one sticky comment on the pull request, found
 * by its hidden marker among the comments of the token's identity and updated
 * in place on every run, passing runs included. A run that cannot comment,
 * such as a fork's, whose token GitHub makes read-only, says why and fails
 * nothing; the step summary carries the same content. The plan job writes
 * it first in its planned state, and the verdict's links the run's HTML
 * report, found as the comment is written (ADR-0009). Each write keeps the
 * record of the owners the comment has mentioned, and mentions only those
 * it has not (ADR-0022).
 *
 * @internal
 */
final readonly class PullRequestComment implements Commenting, CommentThread, Configurable, Deferring, Reporter
{
    private const string COMMENTS = '/repos/%s/issues/%d/comments';

    private const string NO_TOKEN = 'GITHUB_TOKEN is not set, so no comment is written; the step summary carries it.';

    private const string FORK
        = 'A fork\'s pull request gets a read-only token, so no comment is written; the step summary carries it.';

    private const string UNWRITTEN = 'The pull request comment could not be written (%s); the step summary carries it.';

    private function __construct(
        private Api $api,
        private string $repository,
        private PullRequestNumber|NotWritten $pullRequest,
        private string $identity,
        private HtmlReport $report,
        private string $run,
        private bool $commentsHere,
        private CommentSettings $settings,
        private bool $draft,
        private HiddenComments $hidden,
    ) {
    }

    /**
     * The comment of the run these environment variables and this event
     * payload describe; one that says why it cannot be written where they
     * describe no pull request it may comment on, shaped as `comment` says.
     * An identity left empty is asked of GitHub.
     *
     * @param array<string, string> $environment
     */
    public static function inRun(
        array $environment,
        string $event,
        HttpClientInterface $client,
        string $identity,
        CommentSettings $settings,
    ): self {
        $read = static fn(string $name): string => array_key_exists($name, $environment) ? $environment[$name] : '';
        $payload = Node::decode($event)->field('pull_request');
        $number = self::numberIn($payload);
        $commentedOn = str_contains($read('GITHUB_EVENT_NAME'), 'pull_request') && $number instanceof PullRequestNumber
            ? $number
            : NotGiven::value();
        $target = match (true) {
            $commentedOn instanceof NotGiven => NotWritten::because(CommentStates::NOT_A_PULL_REQUEST),
            $read('GITHUB_TOKEN') === '' => NotWritten::because(self::NO_TOKEN),
            self::isFork($payload) => NotWritten::because(self::FORK),
            default => $commentedOn,
        };

        $api = Api::at($client, $read('GITHUB_API_URL'), $read('GITHUB_TOKEN'));
        $run = sprintf(
            '%s/%s/actions/runs/%s',
            $read('GITHUB_SERVER_URL') === '' ? 'https://github.com' : $read('GITHUB_SERVER_URL'),
            $read('GITHUB_REPOSITORY'),
            $read('GITHUB_RUN_ID'),
        );

        return new self(
            $api,
            $read('GITHUB_REPOSITORY'),
            $target,
            $identity,
            HtmlReport::of($api, $read('GITHUB_REPOSITORY'), $read('GITHUB_RUN_ID'), $run),
            $run,
            commentsHere: $commentedOn instanceof PullRequestNumber,
            settings: $settings,
            draft: self::isDraft($payload),
            hidden: HiddenComments::at(
                $client,
                $read('GITHUB_API_URL'),
                $read('GITHUB_GRAPHQL_URL'),
                $read('GITHUB_TOKEN'),
            ),
        );
    }

    /**
     * From the run's own environment and event payload, with `identity` where the token is not GitHub's own, shaped
     * as `with.comment` says.
     */
    public static function fromOptions(Options $options): self
    {
        $identity = $options->text(Key::of('identity'));

        return self::fromEnvironment(
            getenv(),
            HttpClient::create(),
            is_string($identity) ? $identity : '',
            CommentKeys::settingsIn($options),
        );
    }

    /**
     * From these environment variables and the event payload `GITHUB_EVENT_PATH` names, posting with this client;
     * an identity left empty is asked of GitHub.
     *
     * @param array<string, string> $environment
     */
    public static function fromEnvironment(
        array $environment,
        HttpClientInterface $client,
        string $identity,
        CommentSettings $settings,
    ): self {
        $path = Variables::GITHUB_EVENT_PATH;
        $readable = array_key_exists($path, $environment) && is_file($environment[$path]);
        $event = $readable ? sprintf('%s', file_get_contents($environment[$path])) : Node::NO_KEYS;

        return self::inRun($environment, $event, $client, $identity, $settings);
    }

    public function report(Verdict $verdict): Written|NotWritten
    {
        return $this->states()->judged($verdict);
    }

    /**
     * A delivery, with the comment's markdown, where the run is a pull request's, for `deliver` to write with the
     * token its own environment holds (ADR-0007); unchanged on any other run.
     */
    public function deferred(Verdict $verdict, Delivery $delivery): Delivery
    {
        return $this->states()->judgedLater($verdict, $delivery);
    }

    /** The comment in its planned state, which the plan job writes before the verdict replaces it (ADR-0019). */
    public function planned(PlannedWork $work): Written|NotWritten
    {
        return $this->states()->planned($work);
    }

    public function plannedLater(PlannedWork $work, Delivery $delivery): Delivery
    {
        return $this->states()->plannedLater($work, $delivery);
    }

    /**
     * The comment while the last run's survivors are re-checked, written only over its planned state, so a verdict
     * already written is never replaced (ADR-0020).
     */
    public function rechecked(Rechecked $rechecked): Written|NotWritten
    {
        return $this->states()->rechecked($rechecked);
    }

    public function recheckedLater(Rechecked $rechecked, Delivery $delivery): Delivery
    {
        return $this->states()->recheckedLater($rechecked, $delivery);
    }

    public function writeAs(string $markdown, CommentWrite $write): Written|NotWritten
    {
        return $this->states()->writeAs($markdown, $write);
    }

    /**
     * The comment, holding this, written as this says: the one its marker finds updated in place, or posted where
     * there is none, hiding the gate's earlier ones where asked.
     */
    public function written(string $markdown, CommentWrite $write): Written|NotWritten
    {
        $number = $this->pullRequest;

        if ($number instanceof NotWritten) {
            return $number;
        }

        $sticky = StickyComment::in($this->api, $this->repository);
        $author = $sticky->author($this->identity);
        $existing = $sticky->on($number, $author, $write->marker());
        $others = $write->marker() === Markdown::MARKER ? [] : $sticky->othersOn($number, $author, $write->marker());
        $why = $this->whyNot($existing, $others, $write);

        if ($why instanceof NotWritten) {
            return $why;
        }

        $mentioned = $existing instanceof Answer || $others === [] ? $existing : $others[count($others) - 1];
        $written = $this->sent($number, $existing, $mentioned, $author, $markdown);

        if ($written instanceof Written && $write->hidesOld()) {
            $this->hidden->hide($others);
        }

        return $written;
    }

    /**
     * Why the comment is left as it is: not in its planned state, or, where only one there is updated, the gate has
     * not commented before.
     *
     * @param list<Answer> $others the gate's comments of other runs
     */
    private function whyNot(Answer|NotGiven $existing, array $others, CommentWrite $write): NotWritten|NotGiven
    {
        $planned = $existing instanceof Answer && PlannedMarkdown::isPlanned($existing->text('body'));

        return match (true) {
            $write->isOverPlanned() && ! $planned => NotWritten::because(CommentStates::NOT_PLANNED),
            $write->isExistingOnly() && ! $existing instanceof Answer && $others === [] => NotWritten::because(
                CommentStates::NOT_THERE,
            ),
            default => NotGiven::value(),
        };
    }

    /**
     * This markdown, posted, or put in place of the comment there is, mentioning only the owners the comment last
     * written did not.
     */
    private function sent(
        PullRequestNumber $number,
        Answer|NotGiven $existing,
        Answer|NotGiven $mentioned,
        string $author,
        string $markdown,
    ): Written|NotWritten {
        $id = $existing instanceof Answer ? $existing->number('id') : 0;
        $teams = $author !== StickyComment::ACTIONS;
        $mentions = $mentioned instanceof Answer
            ? OwnerMentions::in($mentioned->text('body'), teams: $teams)
            : OwnerMentions::none(teams: $teams);
        $body = ['body' => $mentions->into($this->report->into($markdown))];

        $answer = $id === 0
            ? $this->api->send('POST', sprintf(self::COMMENTS, $this->repository, $number->value()), $body)
            : $this->api->send('PATCH', sprintf('/repos/%s/issues/comments/%d', $this->repository, $id), $body);

        return $answer instanceof CannotTell
            ? NotWritten::because(sprintf(self::UNWRITTEN, $answer->why()))
            : Written::to($answer->text('html_url'));
    }

    /** The states of this comment, written through this thread. */
    private function states(): CommentStates
    {
        return CommentStates::in($this, $this->run, $this->commentsHere, $this->settings, $this->draft);
    }

    private static function numberIn(Node $payload): PullRequestNumber|CannotTell
    {
        try {
            return PullRequestNumber::of($payload->field('number')->integer());
        } catch (NotInShape $misread) {
            return CannotTell::because($misread->getMessage());
        }
    }

    /** Whether the pull request is a draft. */
    private static function isDraft(Node $payload): bool
    {
        try {
            return $payload->field('draft')->isPresent() && $payload->field('draft')->boolean();
        } catch (NotInShape) {
            return false;
        }
    }

    /** Whether the pull request's head is in another repository than its base. */
    private static function isFork(Node $payload): bool
    {
        try {
            return $payload->field('head')->field('repo')->field('full_name')->text()
                !== $payload->field('base')->field('repo')->field('full_name')->text();
        } catch (NotInShape) {
            return false;
        }
    }
}
