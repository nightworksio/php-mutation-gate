<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Gcs;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Http\Exchange;
use NightWorksIO\MutationGate\Core\Http\Token;
use NightWorksIO\MutationGate\Core\Http\Tokens;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\StoreVariable;

use function sprintf;

/**
 * The `gcs` store's token, from OIDC federation and never from a key
 * (ADR-0028 decision 2): a ready one another CI's federation hands over in
 * `MUTATION_GATE_GCS_TOKEN`, or one exchanged for the CI's own: a GitHub
 * Actions job's through the provider and service account the environment
 * names (GitHubIdentity), else through the external-account file
 * `GOOGLE_APPLICATION_CREDENTIALS` names. A file that
 * holds a key is refused wherever it is named, a ready token or not. The
 * token is asked for once, so a run's reads and its write share one exchange.
 */
final class Federation implements Tokens
{
    private const string NONE = 'none of %s, %s and %s is set, so the gcs store has no token to write with.';

    /** The token once asked for, kept for the rest of the process, or why there was none. */
    private Token|CannotJudge|NotGiven $kept;

    private function __construct(
        private readonly Exchange $exchange,
        private readonly Token|ExternalAccount $credential,
    ) {
        $this->kept = NotGiven::value();
    }

    /** The token the environment leads to; or why the store refuses what it names. */
    public static function from(Variables $environment, Exchange $exchange): self|Invalid
    {
        $file = $environment->valueOf(StoreVariable::GoogleCredentials->value);
        $account = $file === '' ? NotGiven::value() : ExternalAccount::at($file);
        $github = GitHubIdentity::in($environment);
        $ready = $environment->valueOf(StoreVariable::GcsToken->value);

        return match (true) {
            $account instanceof Invalid => $account,
            $github instanceof Invalid => $github,
            $ready !== '' => new self($exchange, Token::bearer($ready)),
            $github instanceof ExternalAccount => new self($exchange, $github),
            $account instanceof ExternalAccount => new self($exchange, $account),
            default => Invalid::because(Problem::at('', self::none())),
        };
    }

    public function token(): Token|CannotJudge
    {
        $this->kept = match (true) {
            ! $this->kept instanceof NotGiven => $this->kept,
            $this->credential instanceof Token => $this->credential,
            default => $this->credential->token($this->exchange),
        };

        return $this->kept;
    }

    private static function none(): string
    {
        return sprintf(
            self::NONE,
            StoreVariable::GoogleCredentials->value,
            StoreVariable::GcsProvider->value,
            StoreVariable::GcsToken->value,
        );
    }
}
