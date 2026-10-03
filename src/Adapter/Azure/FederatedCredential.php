<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Azure;

use function http_build_query;
use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\BuiltinStore;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Http\Answer;
use NightWorksIO\MutationGate\Core\Http\Exchange;
use NightWorksIO\MutationGate\Core\Http\MediaType;
use NightWorksIO\MutationGate\Core\Http\Origin;
use NightWorksIO\MutationGate\Core\Http\Request;
use NightWorksIO\MutationGate\Core\Http\Token;
use NightWorksIO\MutationGate\Core\Http\TokenField;
use NightWorksIO\MutationGate\Core\Http\Tokens;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\StoreVariable;

use function rawurlencode;
use function sprintf;
use function str_contains;

/**
 * The `azure` store's token, from OIDC federation and never from a key
 * (ADR-0028 decision 2): a ready one another CI's federation hands over in
 * `MUTATION_GATE_AZURE_TOKEN`, or one Microsoft Entra ID gives for GitHub's
 * own, asked for the audience `api://AzureADTokenExchange` and handed over
 * as a client assertion, with the tenant and client `AZURE_TENANT_ID` and
 * `AZURE_CLIENT_ID` name, as `azure/login` reads them. The token is asked
 * for once, so a run's reads and its write share one exchange.
 */
final class FederatedCredential implements Tokens
{
    /** The audience a federated identity credential expects GitHub's token for. */
    private const string AUDIENCE = 'api://AzureADTokenExchange';

    private const string ENTRA = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';

    /** What the access token may reach: Azure Storage's data. */
    private const string STORAGE = 'https://storage.azure.com/.default';

    private const string ASSERTION = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';

    private const string NONE = 'neither GitHub\'s OIDC token with %s and %s, nor %s, is set for the azure store.';

    /** How a query string begins, and how a parameter joins one that has begun. */
    private const string QUERY = '?';

    /** The token once asked for, kept for the rest of the process, or why there was none. */
    private Token|CannotJudge|NotGiven $kept;

    private function __construct(private readonly Exchange $exchange, private readonly Variables $environment)
    {
        $this->kept = NotGiven::value();
    }

    /** The token the environment leads to; or why there is none to be had. */
    public static function from(Variables $environment, Exchange $exchange): self|Invalid
    {
        return BuiltinStore::Azure->credentials()->heldIn($environment)
            ? new self($exchange, $environment)
            : Invalid::because(Problem::at('', self::none()));
    }

    public function token(): Token|CannotJudge
    {
        $this->kept = $this->kept instanceof NotGiven ? $this->asked() : $this->kept;

        return $this->kept;
    }

    private function asked(): Token|CannotJudge
    {
        $ready = $this->environment->valueOf(StoreVariable::AzureToken->value);
        $github = $ready === '' ? $this->github() : $ready;

        return match (true) {
            $github instanceof CannotJudge => $github,
            $ready !== '' => Token::bearer($ready),
            default => $this->exchanged($github),
        };
    }

    private static function none(): string
    {
        return sprintf(
            self::NONE,
            StoreVariable::AzureTenant->value,
            StoreVariable::AzureClient->value,
            StoreVariable::AzureToken->value,
        );
    }

    /** GitHub's OIDC token for the federated credential's audience; or why GitHub gave none. */
    private function github(): string|CannotJudge
    {
        $url = $this->environment->valueOf(StoreVariable::OidcRequestUrl->value);
        $joined = str_contains($url, self::QUERY) ? '&' : self::QUERY;
        $request = Request::get(sprintf('%s%saudience=%s', $url, $joined, rawurlencode(self::AUDIENCE)))
            ->carrying(Token::bearer($this->environment->valueOf(StoreVariable::OidcRequestToken->value)));

        return Answer::field($this->exchange->answer($request), TokenField::GitHubOidc, Origin::of($url));
    }

    /** The access token Microsoft Entra ID gives for GitHub's; or why it gives none. */
    private function exchanged(string $github): Token|CannotJudge
    {
        $url = sprintf(self::ENTRA, rawurlencode($this->environment->valueOf(StoreVariable::AzureTenant->value)));
        $request = Request::post(
            $url,
            http_build_query([
                'client_id' => $this->environment->valueOf(StoreVariable::AzureClient->value),
                'scope' => self::STORAGE,
                'grant_type' => 'client_credentials',
                'client_assertion_type' => self::ASSERTION,
                'client_assertion' => $github,
            ]),
        )->sending(MediaType::Form);
        $token = Answer::field($this->exchange->answer($request), TokenField::OAuth, Origin::of($url));

        return is_string($token) ? Token::bearer($token) : $token;
    }
}
