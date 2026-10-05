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
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Delivery\Deferring;
use NightWorksIO\MutationGate\Core\Delivery\Delivery;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Plan\PlannedWork;
use NightWorksIO\MutationGate\Core\Recheck\Rechecked;
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
 * it first in its planned state (ADR-0009, decision 3).
 */
final readonly class PullRequestComment implements Configurable, Deferring, Reporter
{
    private const string COMMENTS = '/repos/%s/issues/%d/comments';

    private const string NOT_A_PULL_REQUEST = 'This run is not for a pull request, so there is no comment to write.';

    private const string NO_TOKEN = 'GITHUB_TOKEN is not set, so no comment is written; the step summary carries it.';

    private const string FORK
        = 'A fork\'s pull request gets a read-only token, so no comment is written; the step summary carries it.';

    private const string UNWRITTEN = 'The pull request comment could not be written (%s); the step summary carries it.';

    private const string NOT_PLANNED
        = 'The pull request comment is no longer in its planned state, so the re-checked survivors leave it as it is.';

    private function __construct(
        private Api $api,
        private string $repository,
        private PullRequestNumber|NotWritten $pullRequest,
        private PullRequestNumber|NotGiven $commentedOn,
        private string $run,
        private string $identity,
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
            $commentedOn instanceof NotGiven => NotWritten::because(self::NOT_A_PULL_REQUEST),
            $read('GITHUB_TOKEN') === '' => NotWritten::because(self::NO_TOKEN),
            self::isFork($payload) => NotWritten::because(self::FORK),
            default => $commentedOn,
        };

        return new self(
            Api::at($client, $read('GITHUB_API_URL'), $read('GITHUB_TOKEN')),
            $read('GITHUB_REPOSITORY'),
            $target,
            $commentedOn,
            sprintf(
                '%s/%s/actions/runs/%s',
                $read('GITHUB_SERVER_URL') === '' ? 'https://github.com' : $read('GITHUB_SERVER_URL'),
                $read('GITHUB_REPOSITORY'),
                $read('GITHUB_RUN_ID'),
            ),
            $identity,
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
        $eventPath = array_key_exists('GITHUB_EVENT_PATH', $environment) ? $environment['GITHUB_EVENT_PATH'] : '';
        $event = $eventPath !== '' && is_file($eventPath) ? file_get_contents($eventPath) : '';

        return self::inRun($environment, $event === false ? '' : $event, $client, $identity);
    }

    public function report(Verdict $verdict): Written|NotWritten
    {
        return $this->write(Markdown::comment($verdict, $this->run));
    }

    /**
     * The comment in its planned state, which the plan job writes before the
     * verdict replaces it (ADR-0019, decision 11).
     */
    public function planned(PlannedWork $work): Written|NotWritten
    {
        return $this->write(PlannedMarkdown::comment($work, $this->run));
    }

    /**
     * A delivery, with the comment's markdown, where the run is a pull request's, for `deliver` to write with the
     * token its own environment holds (ADR-0007 decision 5); unchanged on any other run.
     */
    public function deferred(Verdict $verdict, Delivery $delivery): Delivery
    {
        return $this->commentedOn instanceof NotGiven
            ? $delivery
            : $delivery->withComment(Markdown::comment($verdict, $this->run));
    }

    /** A delivery, with the comment in its planned state, where the run is a pull request's, for `deliver` to write. */
    public function plannedLater(PlannedWork $work, Delivery $delivery): Delivery
    {
        return $this->commentedOn instanceof NotGiven
            ? $delivery
            : $delivery->withComment(PlannedMarkdown::comment($work, $this->run));
    }

    /**
     * The comment while the last run's survivors are re-checked, written only
     * over its planned state, so a verdict already written is never replaced
     * (ADR-0020, decision 21).
     */
    public function rechecked(Rechecked $rechecked): Written|NotWritten
    {
        return $this->writeOverPlanned(RecheckedMarkdown::comment($rechecked, $this->run));
    }

    /** A delivery, with the comment while the survivors are re-checked, where the run is a pull request's. */
    public function recheckedLater(Rechecked $rechecked, Delivery $delivery): Delivery
    {
        return $this->commentedOn instanceof NotGiven
            ? $delivery
            : $delivery->withCommentOverPlanned(RecheckedMarkdown::comment($rechecked, $this->run));
    }

    /**
     * The sticky comment, holding this, written only over its planned state: what `deliver` writes of the comment
     * a re-check left for it.
     */
    public function writeOverPlanned(string $markdown): Written|NotWritten
    {
        return $this->written($markdown, overPlanned: true);
    }

    /**
     * The sticky comment, holding this, written or updated in place: what `deliver` writes of a comment a run left
     * for it (ADR-0007 decision 5).
     */
    public function write(string $markdown): Written|NotWritten
    {
        return $this->written($markdown, overPlanned: false);
    }

    /** The sticky comment, holding this, written or updated in place; over its planned state alone, where asked. */
    private function written(string $markdown, bool $overPlanned): Written|NotWritten
    {
        $number = $this->pullRequest;

        if ($number instanceof NotWritten) {
            return $number;
        }

        $body = ['body' => $markdown];
        $existing = StickyComment::in($this->api, $this->repository)->on($number, $this->identity);
        $id = $existing instanceof Answer ? $existing->number('id') : 0;
        $planned = $existing instanceof Answer && str_contains($existing->text('body'), $this->plannedHeading());

        if ($overPlanned && ! $planned) {
            return NotWritten::because(self::NOT_PLANNED);
        }

        $answer = $id === 0
            ? $this->api->send('POST', sprintf(self::COMMENTS, $this->repository, $number->value()), $body)
            : $this->api->send('PATCH', sprintf('/repos/%s/issues/comments/%d', $this->repository, $id), $body);

        return $answer instanceof CannotTell
            ? NotWritten::because(sprintf(self::UNWRITTEN, $answer->why()))
            : Written::to($answer->text('html_url'));
    }

    /** The heading of the planned state, on a line of its own, as the comment holds it. */
    private function plannedHeading(): string
    {
        return sprintf("\n%s\n", PlannedMarkdown::HEADING);
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
