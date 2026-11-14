<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use function array_key_exists;
use function count;
use function explode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

use function max;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Format\Bytes;
use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Http\Exchange;
use NightWorksIO\MutationGate\Core\Http\MediaType;
use NightWorksIO\MutationGate\Core\Http\Reply;
use NightWorksIO\MutationGate\Core\Http\Request;
use NightWorksIO\MutationGate\Core\Http\Token;

use function rtrim;
use function sodium_base642bin;

use const SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING;

use SodiumException;

use function sprintf;
use function str_starts_with;

/**
 * The artifacts of the workflow run, reached through the artifact service
 * with the job's runtime token, which names the run and the job it writes
 * for (ADR-0134).
 *
 * @internal
 */
final readonly class RunArtifacts
{
    /** What a method of the service answered where it is not JSON. */
    public const string UNREAD = 'The artifact service answered %s with what is not JSON.';
    private const string ATTEMPT = 'GITHUB_RUN_ATTEMPT';

    private const string SERVICE = '%s/twirp/github.actions.results.api.v1.ArtifactService/%s';

    /** The scope of the runtime token that names the run's and the job's backend ids. */
    private const string RESULTS_SCOPE = 'Actions.Results:';

    private const string RUN_ID = 'workflow_run_backend_id';

    private const string JOB_ID = 'workflow_job_run_backend_id';

    private const string NO_SCOPE = 'The runtime token names no run to write artifacts to.';

    private const string REFUSED = 'The artifact service answered %s with %d.';

    private function __construct(
        private Exchange $exchange,
        private string $service,
        private Token $token,
        private string $run,
        private string $job,
    ) {
    }

    /** The run's artifacts, as the service the gate was handed reaches them; or why its token names no run. */
    public static function of(ArtifactAccess $service, Exchange $exchange): self|CannotJudge
    {
        $ids = self::backendIds($service->token());

        return $ids === [] ? CannotJudge::because(self::NO_SCOPE) : new self(
            $exchange,
            rtrim($service->url(), '/'),
            Token::bearer($service->token()),
            $ids[0],
            $ids[1],
        );
    }

    /** The run's attempt, as the environment names it; the first where it names none. */
    public static function attempt(Variables $environment): int
    {
        return max(1, (int) $environment->valueOf(self::ATTEMPT));
    }

    /**
     * The name of every artifact of the run the service lists.
     *
     * @return list<string>|CannotJudge
     */
    public function names(): array|CannotJudge
    {
        $listed = $this->called(ArtifactMethod::ListArtifacts, []);

        if ($listed instanceof CannotJudge) {
            return $listed;
        }

        $names = [];

        foreach (Lenient::items($listed->field('artifacts')) as $artifact) {
            $name = Lenient::text($artifact->field('name'));
            $names = $name === '' ? $names : [...$names, $name];
        }

        return $names;
    }

    /**
     * What the service answers a method, before it is read.
     *
     * @param array<string, string|int> $fields
     */
    public function answer(ArtifactMethod $method, array $fields): Reply|CannotJudge
    {
        return $this->exchange->answer($this->request($method, $fields));
    }

    /** What an exchange outside the service answers. */
    public function sent(Request $request): Reply|CannotJudge
    {
        return $this->exchange->answer($request);
    }

    /**
     * What a method of the service answers, as JSON; or why it answered nothing readable.
     *
     * @param array<string, string|int> $fields
     */
    public function called(ArtifactMethod $method, array $fields): Node|CannotJudge
    {
        return $this->read($method, $this->answer($method, $fields));
    }

    /** What a method of the service answered, as a JSON object or list; or why it is not one. */
    public function read(ArtifactMethod $method, Reply|CannotJudge $answer): Node|CannotJudge
    {
        if (! $answer instanceof Reply || ! $answer->isAccepted()) {
            return $this->refusal($method->value, $answer);
        }

        $decoded = Node::decode($answer->body(), $method->value);

        return Lenient::holdsMembers($decoded) ? $decoded : CannotJudge::because(sprintf(self::UNREAD, $method->value));
    }

    /** Why what the reader knows as this answered nothing the gate can use. */
    public function refusal(string $what, Reply|CannotJudge $answer): CannotJudge
    {
        return $answer instanceof CannotJudge
            ? $answer
            : CannotJudge::because(sprintf(self::REFUSED, $what, $answer->status()));
    }

    /**
     * The run's and the job's backend ids, as the runtime token's results scope names them; none where it names none.
     *
     * @return list<string>
     */
    private static function backendIds(string $token): array
    {
        $scopes = Lenient::text(Node::decode(self::claimsOf($token))->field('scp'));

        foreach (explode(' ', $scopes) as $scope) {
            $ids = str_starts_with($scope, self::RESULTS_SCOPE)
                ? explode(':', Bytes::from($scope, Bytes::length(self::RESULTS_SCOPE)))
                : [];

            if (count($ids) === 2 && $ids[0] !== '' && $ids[1] !== '') {
                return $ids;
            }
        }

        return [];
    }

    /** The claims a JSON web token carries, as their JSON; none where it carries none. */
    private static function claimsOf(string $token): string
    {
        $parts = explode('.', $token);

        try {
            return array_key_exists(1, $parts)
                ? sodium_base642bin($parts[1], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)
                : Node::NO_KEYS;
        } catch (SodiumException) {
            return Node::NO_KEYS;
        }
    }

    /** @param array<string, string|int> $fields */
    private function request(ArtifactMethod $method, array $fields): Request
    {
        return Request::post(
            sprintf(self::SERVICE, $this->service, $method->value),
            json_encode([self::RUN_ID => $this->run, self::JOB_ID => $this->job, ...$fields], JSON_THROW_ON_ERROR),
        )->sending(MediaType::Json)->carrying($this->token);
    }
}
