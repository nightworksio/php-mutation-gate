<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use function array_key_exists;
use function count;
use function file_get_contents;
use function getenv;
use function is_file;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\PullRequestNumber;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Plan\PlannedWork;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Configurable;
use NightWorksIO\MutationGate\Extension\Options;
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
final readonly class PullRequestComment implements Configurable, Reporter
{
    /** The identity `GITHUB_TOKEN` comments as, which cannot read `/user`. */
    public const string ACTIONS = 'github-actions[bot]';

    private const string COMMENTS = '/repos/%s/issues/%d/comments';

    private const int PAGE = 100;

    /** The most pages of comments read to find the sticky one. */
    private const int PAGES = 30;

    private const string NOT_A_PULL_REQUEST = 'This run is not for a pull request, so there is no comment to write.';

    private const string NO_TOKEN = 'GITHUB_TOKEN is not set, so no comment is written; the step summary carries it.';

    private const string FORK
        = 'A fork\'s pull request gets a read-only token, so no comment is written; the step summary carries it.';

    private const string UNWRITTEN = 'The pull request comment could not be written (%s); the step summary carries it.';

    private function __construct(
        private Api $api,
        private string $repository,
        private PullRequestNumber|NotWritten $pullRequest,
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
        $target = match (true) {
            ! str_contains($read('GITHUB_EVENT_NAME'), 'pull_request') || ! $number instanceof PullRequestNumber
                => NotWritten::because(self::NOT_A_PULL_REQUEST),
            $read('GITHUB_TOKEN') === '' => NotWritten::because(self::NO_TOKEN),
            self::isFork($payload) => NotWritten::because(self::FORK),
            default => $number,
        };

        return new self(
            Api::at($client, $read('GITHUB_API_URL'), $read('GITHUB_TOKEN')),
            $read('GITHUB_REPOSITORY'),
            $target,
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
        $environment = getenv();
        $eventPath = array_key_exists('GITHUB_EVENT_PATH', $environment) ? $environment['GITHUB_EVENT_PATH'] : '';
        $event = $eventPath !== '' && is_file($eventPath) ? file_get_contents($eventPath) : '';

        $identity = Node::decode($options->json())->field('identity');

        try {
            $named = $identity->isPresent() ? $identity->text() : '';
        } catch (NotInShape) {
            $named = '';
        }

        return self::inRun($environment, $event === false ? '' : $event, HttpClient::create(), $named);
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

    /** The sticky comment, holding this, written or updated in place. */
    private function write(string $markdown): Written|NotWritten
    {
        $number = $this->pullRequest;

        if ($number instanceof NotWritten) {
            return $number;
        }

        $body = ['body' => $markdown];
        $existing = $this->existing($number, $this->identity === '' ? $this->identityOfToken() : $this->identity);
        $answer = $existing === 0
            ? $this->api->send('POST', sprintf(self::COMMENTS, $this->repository, $number->value()), $body)
            : $this->api->send('PATCH', sprintf('/repos/%s/issues/comments/%d', $this->repository, $existing), $body);

        return $answer instanceof CannotTell
            ? NotWritten::because(sprintf(self::UNWRITTEN, $answer->why()))
            : Written::to($answer->text('html_url'));
    }

    /** Who the token comments as: its user, or GitHub Actions' own bot where it cannot read one. */
    private function identityOfToken(): string
    {
        $user = $this->api->get('/user');
        $login = $user instanceof Answer ? $user->text('login') : '';

        return $login === '' ? self::ACTIONS : $login;
    }

    /** The id of the sticky comment this identity wrote on the pull request; 0 where there is none. */
    private function existing(PullRequestNumber $number, string $identity): int
    {
        $found = 0;
        $full = true;

        for ($page = 1; $found === 0 && $full && $page <= self::PAGES; ++$page) {
            $comments = $this->api->get(sprintf(
                '/repos/%s/issues/%d/comments?per_page=%d&page=%d',
                $this->repository,
                $number->value(),
                self::PAGE,
                $page,
            ));
            $items = $comments instanceof Answer ? $comments->items() : [];
            $found = $this->stickyAmong($items, $identity);
            $full = count($items) === self::PAGE;
        }

        return $found;
    }

    /**
     * The id of the comment among these that this identity wrote with the marker; 0 where none is.
     *
     * @param list<Answer> $comments
     */
    private function stickyAmong(array $comments, string $identity): int
    {
        foreach ($comments as $comment) {
            $sticky = str_contains($comment->text('body'), Markdown::MARKER);

            if ($sticky && $comment->text('user', 'login') === $identity) {
                return $comment->number('id');
            }
        }

        return 0;
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
