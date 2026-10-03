<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Gcs;

use function file_get_contents;
use function http_build_query;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Format;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Http\Answer;
use NightWorksIO\MutationGate\Core\Http\Exchange;
use NightWorksIO\MutationGate\Core\Http\MediaType;
use NightWorksIO\MutationGate\Core\Http\Origin;
use NightWorksIO\MutationGate\Core\Http\Request;
use NightWorksIO\MutationGate\Core\Http\Token;
use NightWorksIO\MutationGate\Core\Http\TokenField;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\StoreVariable;

use function sprintf;
use function str_starts_with;

/**
 * The external-account credentials file Workload Identity Federation reads,
 * as `google-github-actions/auth` writes it: the CI's token, where to
 * exchange it at Google's security token service, and the service account
 * to impersonate, where it names one (ADR-0028 decision 2). Only the `file`
 * and `url` credential sources are read, which are what CI identity
 * providers write. A file that holds a service-account key, or any other
 * long-lived credential, is refused.
 */
final readonly class ExternalAccount
{
    /** Google's security token service, the only place a CI's token is exchanged. */
    private const string STS = 'https://sts.googleapis.com';

    /** The IAM Service Account Credentials API, the only place a service account is impersonated at. */
    private const string IAM = 'https://iamcredentials.googleapis.com';

    /** What an access token for Cloud Storage may do: read and write objects. */
    private const string STORAGE = 'https://www.googleapis.com/auth/devstorage.read_write';

    /** What the federated token may do where it impersonates a service account, which asks IAM. */
    private const string PLATFORM = 'https://www.googleapis.com/auth/cloud-platform';

    private const string EXCHANGE = 'urn:ietf:params:oauth:grant-type:token-exchange';

    private const string ACCESS_TOKEN = 'urn:ietf:params:oauth:token-type:access_token';

    private const string KEY = 'service_account';

    private const string FEDERATED = 'external_account';

    /** The scheme a URL source must use, since its request carries the CI's credential. */
    private const string HTTPS = 'https://';

    private const string REFUSED_KEY
        = '%s names a service-account key, which the gcs store refuses: use Workload Identity Federation instead.';

    private const string REFUSED_TYPE
        = '%s names "%s" credentials; the gcs store reads only an external_account file, from federation.';

    private const string UNREAD = '%s names %s, which cannot be read.';

    private const string INCOMPLETE = '%s names an external_account file without %s.';

    private const string ELSEWHERE = '%s names an external_account file whose %s is not at %s.';

    private const string SOURCE
        = '%s names an external_account file whose credential source is neither a file nor an https URL.';

    private function __construct(
        private string $audience,
        private string $subjectType,
        private string $tokenUrl,
        private string|NotGiven $impersonation,
        private SubjectSource $source,
    ) {
    }

    /** The file at this path, or why the store refuses it. */
    public static function at(string $path): self|Invalid
    {
        $text = is_file($path) ? file_get_contents($path) : false;

        return is_string($text)
            ? self::read(Node::decode($text, StoreVariable::GoogleCredentials->value))
            : self::refused(sprintf(self::UNREAD, StoreVariable::GoogleCredentials->value, $path));
    }

    /** An access token for Cloud Storage, exchanged for the CI's own; or why there is none. */
    public function token(Exchange $exchange): Token|CannotJudge
    {
        $subject = $this->source->token($exchange);
        $federated = is_string($subject) ? $this->exchanged($exchange, $subject) : $subject;

        return $federated instanceof Token ? $this->impersonated($exchange, $federated) : $federated;
    }

    /** The federated token Google's security token service gives for the CI's own; or why it gives none. */
    private function exchanged(Exchange $exchange, string $subject): Token|CannotJudge
    {
        $request = Request::post(
            $this->tokenUrl,
            http_build_query([
                'grant_type' => self::EXCHANGE,
                'audience' => $this->audience,
                'scope' => $this->impersonation instanceof NotGiven ? self::STORAGE : self::PLATFORM,
                'requested_token_type' => self::ACCESS_TOKEN,
                'subject_token' => $subject,
                'subject_token_type' => $this->subjectType,
            ]),
        )->sending(MediaType::Form);
        $token = Answer::field($exchange->answer($request), TokenField::OAuth, Origin::of($this->tokenUrl));

        return is_string($token) ? Token::bearer($token) : $token;
    }

    /** The service account's token, where the file names one to impersonate; else the federated token itself. */
    private function impersonated(Exchange $exchange, Token $federated): Token|CannotJudge
    {
        if ($this->impersonation instanceof NotGiven) {
            return $federated;
        }

        $token = Answer::field(
            $exchange->answer(Request::post($this->impersonation, JsonText::compact(['scope' => [self::STORAGE]]))
                ->carrying($federated)
                ->sending(MediaType::Json)),
            TokenField::Impersonated,
            Origin::of($this->impersonation),
        );

        return is_string($token) ? Token::bearer($token) : $token;
    }

    private static function read(Node $file): self|Invalid
    {
        $type = Lenient::text($file->field('type'));
        $named = StoreVariable::GoogleCredentials->value;

        return match ($type) {
            self::FEDERATED => self::federated($file),
            self::KEY => self::refused(sprintf(self::REFUSED_KEY, $named)),
            default => self::refused(sprintf(self::REFUSED_TYPE, $named, $type)),
        };
    }

    private static function federated(Node $file): self|Invalid
    {
        $named = StoreVariable::GoogleCredentials->value;
        $tokenUrl = Lenient::text($file->field('token_url'));
        $impersonation = $file->field('service_account_impersonation_url');
        $impersonating = $impersonation->isPresent() ? Lenient::text($impersonation) : NotGiven::value();
        $source = self::source($file->field('credential_source'));
        $missing = self::missing($file);

        return match (true) {
            $missing !== '' => self::refused(sprintf(self::INCOMPLETE, $named, $missing)),
            Origin::of($tokenUrl) !== self::STS => self::refused(
                sprintf(self::ELSEWHERE, $named, 'token_url', self::STS),
            ),
            is_string($impersonating) && Origin::of($impersonating) !== self::IAM => self::refused(
                sprintf(self::ELSEWHERE, $named, 'service_account_impersonation_url', self::IAM),
            ),
            ! $source instanceof SubjectSource => self::refused(sprintf(self::SOURCE, $named)),
            default => new self(
                Lenient::text($file->field('audience')),
                Lenient::text($file->field('subject_token_type')),
                $tokenUrl,
                $impersonating,
                $source,
            ),
        };
    }

    /** The first field the file must give and does not; nothing where it gives them all. */
    private static function missing(Node $file): string
    {
        foreach (['audience', 'subject_token_type', 'token_url'] as $field) {
            if (Lenient::text($file->field($field)) === '') {
                return $field;
            }
        }

        return '';
    }

    private static function source(Node $source): SubjectSource|NotGiven
    {
        $format = $source->field('format');
        $field = Lenient::text($format->field('type')) === Format::Json->value
            ? Lenient::text($format->field('subject_token_field_name'))
            : '';
        $file = Lenient::text($source->field('file'));
        $url = Lenient::text($source->field('url'));

        return match (true) {
            $file !== '' => SubjectSource::file($file, $field),
            str_starts_with($url, self::HTTPS) && Origin::of($url) !== Origin::UNREAD => SubjectSource::url(
                self::headed(Request::get($url), $source->field('headers')),
                $field,
            ),
            default => NotGiven::value(),
        };
    }

    /** The request, with each header the source names. */
    private static function headed(Request $request, Node $headers): Request
    {
        foreach (Lenient::entries($headers) as $name => $value) {
            $request = $request->with(sprintf('%s', $name), Lenient::text($value));
        }

        return $request;
    }

    private static function refused(string $why): Invalid
    {
        return Invalid::because(Problem::at('', $why));
    }
}
