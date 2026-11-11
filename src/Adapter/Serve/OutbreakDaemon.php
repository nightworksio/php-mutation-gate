<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Serve;

use function ceil;
use function json_encode;

use NightWorksIO\MutationGate\Core\File\Workspace;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Reach\GraphAnswer;
use NightWorksIO\MutationGate\Core\Reach\GraphAnswered;
use NightWorksIO\MutationGate\Core\Reach\GraphAsked;
use NightWorksIO\MutationGate\Core\Reach\GraphQuestion;
use NightWorksIO\MutationGate\Core\Serve\KeepsProofs;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Watch\NoticesChanges;
use NightWorksIO\MutationGate\Core\Watch\Unwatched;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\ReachGraph;

use function round;
use function sprintf;

/**
 * `outbreak serve` as the gate asks it (ADR-0133), for the proofs it holds,
 * for the static graph it keeps (ADR-0132) and for the changes it watches
 * the files and the power source for, waiting for one as long as it asks on
 * top of `outbreak.timeout`: one request a connection on
 * `.mutation-gate/outbreak/state.sock`, a verb and its JSON on one line, its
 * answer one line of JSON in contract 1, once the socket, its directory and
 * the process answering are found to be the user's own. No daemon, an answer
 * that does not come within `outbreak.timeout`, a refusal or any other
 * answer is no answer.
 *
 * @internal
 */
final readonly class OutbreakDaemon implements KeepsProofs, NoticesChanges, ReachGraph
{
    private const string UNSERVED = 'No outbreak serve answers on %s.';

    private const string REFUSED = 'outbreak serve refused the request: %s';

    private const string UNREAD = 'outbreak serve answered in another shape: %s';

    private const string KEPT = 'outbreak serve kept %d proofs.';

    private const string FORGOT = 'outbreak serve forgot %d proofs.';

    /** The verb `watch` asks for the next change of the files with, and the member each such answer holds. */
    private const string CHANGED = 'changed';

    /** The verb `watch` asks for the next change of the power source with. */
    private const string POWER = 'power';

    /** The contract every answer is written in. */
    private const int CONTRACT = 1;

    /** What a message calls the daemon's answer. */
    private const string ANSWER = 'outbreak serve\'s answer';

    public function __construct(private ServeSocket $socket, private Seconds $timeout, private string $root)
    {
    }

    /** The daemon of the project at this root, as its owner asks it, waiting this long for each answer. */
    public static function of(string $root, int $owner, Seconds $timeout): self
    {
        $socket = sprintf('%s/%s', $root, Workspace::servedState()->value());

        return new self(ServeSocket::at($socket, $owner), $timeout, $root);
    }

    public function proofs(string ...$keys): array|NotWritten
    {
        $said = $this->asked('proofs', ['keys' => $keys], Seconds::none());

        try {
            if ($said instanceof NotWritten) {
                return $said;
            }

            $proofs = [];

            foreach ($said->field('proofs')->entries() as $key => $proof) {
                $proofs[$key] = $proof->text();
            }

            return $proofs;
        } catch (NotInShape $unread) {
            return NotWritten::because(sprintf(self::UNREAD, $unread->getMessage()));
        }
    }

    public function kept(array $proofs): Written|NotWritten
    {
        return $this->counted('keep', ['proofs' => (object) $proofs], 'kept', self::KEPT);
    }

    public function forgotten(string ...$keys): Written|NotWritten
    {
        return $this->counted('forget', ['keys' => $keys], 'forgot', self::FORGOT);
    }

    public function answered(GraphQuestion $question): GraphAnswer|NotWritten
    {
        $said = $this->line('graph', GraphAsked::of($question, $this->root), Seconds::none());

        return $said instanceof Node ? GraphAnswered::of($said) : $said;
    }

    public function changed(array $directories, int $since, Seconds $wait): int|Unwatched|NotWritten
    {
        return $this->generation(self::CHANGED, ['paths' => $directories], $since, $wait);
    }

    public function powered(int $since, Seconds $wait): int|Unwatched|NotWritten
    {
        return $this->generation(self::POWER, [], $since, $wait);
    }

    /**
     * The generation a request waiting for a change is answered with, or why the daemon watches none.
     *
     * @param array<string, mixed> $request
     */
    private function generation(string $verb, array $request, int $since, Seconds $wait): int|Unwatched|NotWritten
    {
        $milliseconds = (int) round($wait->seconds() * Seconds::MILLISECONDS);
        $said = $this->asked($verb, [...$request, 'since' => $since, 'wait' => $milliseconds], $wait);

        try {
            if ($said instanceof NotWritten) {
                return $said;
            }

            $unwatched = $said->field('unwatched');

            return $unwatched->isPresent()
                ? Unwatched::because($unwatched->text())
                : $said->field(self::CHANGED)->integer();
        } catch (NotInShape $unread) {
            return NotWritten::because(sprintf(self::UNREAD, $unread->getMessage()));
        }
    }

    /**
     * What a request that changes the proofs held did, as the count its answer holds says it; or why it did not.
     *
     * @param array<string, mixed> $request
     */
    private function counted(string $verb, array $request, string $count, string $said): Written|NotWritten
    {
        $answer = $this->asked($verb, $request, Seconds::none());

        try {
            return $answer instanceof Node
                ? Written::noting($this->socket->path(), sprintf($said, $answer->field($count)->integer()))
                : $answer;
        } catch (NotInShape $unread) {
            return NotWritten::because(sprintf(self::UNREAD, $unread->getMessage()));
        }
    }

    /** @param array<string, mixed> $request */
    private function asked(string $verb, array $request, Seconds $longer): Node|NotWritten
    {
        return $this->line($verb, (string) json_encode($request, JsonText::FLAGS), $longer);
    }

    /** The daemon's answer to one request line, waited for this much longer than the timeout; or why there is none. */
    private function line(string $verb, string $json, Seconds $longer): Node|NotWritten
    {
        $connection = $this->socket->connected();

        if (! $connection instanceof Connection) {
            return NotWritten::because(sprintf(self::UNSERVED, $this->socket->path()));
        }

        $connection->waitingAtMost((int) ceil($this->timeout->seconds() + $longer->seconds()));
        $answer = $connection->sent(sprintf("%s %s\n", $verb, $json)) ? $connection->line() : NotGiven::value();
        $connection->closed();

        return $answer instanceof NotGiven
            ? NotWritten::because(sprintf(self::UNSERVED, $this->socket->path()))
            : self::read($answer);
    }

    /** An answer's line, where it is in the contract and refuses nothing; or why it is no answer. */
    private static function read(string $answer): Node|NotWritten
    {
        $said = Node::decode($answer, self::ANSWER);
        $refused = $said->field('refused');

        try {
            return match (true) {
                $said->field('outbreak')->integer() !== self::CONTRACT => NotWritten::because(
                    sprintf(self::UNREAD, $answer),
                ),
                $refused->isPresent() => NotWritten::because(sprintf(self::REFUSED, $refused->text())),
                default => $said,
            };
        } catch (NotInShape $unread) {
            return NotWritten::because(sprintf(self::UNREAD, $unread->getMessage()));
        }
    }
}
