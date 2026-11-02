<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use function array_key_exists;
use function file_get_contents;
use function getenv;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\PullRequestNumber;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Delivery\Commenting;
use NightWorksIO\MutationGate\Core\Delivery\CommentStates;
use NightWorksIO\MutationGate\Core\Delivery\CommentThread;
use NightWorksIO\MutationGate\Core\Delivery\Deferring;
use NightWorksIO\MutationGate\Core\Delivery\Delivery;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Plan\PlannedWork;
use NightWorksIO\MutationGate\Core\Recheck\Rechecked;
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
 * report, found as the comment is written (ADR-0009).
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
    ) {
    }

    /**
     * The comment of the run these environment variables and this event
     * payload describe; one that says why it cannot be written where they
     * describe no pull request it may comment on. An identity left empty is
     * asked of GitHub.
     *
     * @param array<string, string> $environment
     */
    public static function inRun(
        array $environment,
        string $event,
        HttpClientInterface $client,
        string $identity,
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
        );
    }

    /** From the run's own environment and event payload, with `identity` where the token is not GitHub's own. */
    public static function fromOptions(Options $options): self
    {
        $identity = $options->text(Key::of('identity'));

        return self::fromEnvironment(getenv(), HttpClient::create(), is_string($identity) ? $identity : '');
    }

    /**
     * From these environment variables and the event payload `GITHUB_EVENT_PATH` names, posting with this client;
     * an identity left empty is asked of GitHub.
     *
     * @param array<string, string> $environment
     */
    public static function fromEnvironment(array $environment, HttpClientInterface $client, string $identity): self
    {
        $path = Variables::GITHUB_EVENT_PATH;
        $readable = array_key_exists($path, $environment) && is_file($environment[$path]);
        $event = $readable ? sprintf('%s', file_get_contents($environment[$path])) : Node::NO_KEYS;

        return self::inRun($environment, $event, $client, $identity);
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

    public function writeOverPlanned(string $markdown): Written|NotWritten
    {
        return $this->states()->writeOverPlanned($markdown);
    }

    public function write(string $markdown): Written|NotWritten
    {
        return $this->states()->write($markdown);
    }

    /** The sticky comment, holding this, written or updated in place; over its planned state alone, where asked. */
    public function written(string $markdown, bool $overPlanned): Written|NotWritten
    {
        $number = $this->pullRequest;

        if ($number instanceof NotWritten) {
            return $number;
        }

        $existing = StickyComment::in($this->api, $this->repository)->on($number, $this->identity);
        $id = $existing instanceof Answer ? $existing->number('id') : 0;
        $planned = $existing instanceof Answer && PlannedMarkdown::isPlanned($existing->text('body'));

        if ($overPlanned && ! $planned) {
            return NotWritten::because(CommentStates::NOT_PLANNED);
        }

        $body = ['body' => $this->report->into($markdown)];

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
        return CommentStates::in($this, $this->run, $this->commentsHere);
    }

    private static function numberIn(Node $payload): PullRequestNumber|CannotTell
    {
        try {
            return PullRequestNumber::of($payload->field('number')->integer());
        } catch (NotInShape $misread) {
            return CannotTell::because($misread->getMessage());
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
