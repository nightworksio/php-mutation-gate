<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\S3;

use AsyncAws\Core\Credentials\ConfigurationProvider;
use AsyncAws\Core\Exception\Exception as AwsFailure;
use AsyncAws\Core\Exception\Http\HttpException;
use AsyncAws\S3\Exception\NoSuchKeyException;
use AsyncAws\S3\S3Client;

use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Bytes;
use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\Format\TooLarge;
use NightWorksIO\MutationGate\Core\Http\Reply;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Proof\Companion;
use NightWorksIO\MutationGate\Core\Proof\CompanionRead;
use NightWorksIO\MutationGate\Core\Proof\EncodedLedger;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\LedgerFile;
use NightWorksIO\MutationGate\Core\Proof\LedgerLimits;
use NightWorksIO\MutationGate\Core\Proof\LedgerObject;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Unreadable;
use NightWorksIO\MutationGate\Core\Proof\UnreadReason;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Configurable;
use NightWorksIO\MutationGate\Port\ProofStore;

use function sprintf;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The proof store `s3`: one object per scope, `<prefix>/<scope>/ledger.json.gz`,
 * in a bucket of AWS S3, Cloudflare R2, MinIO or anything else that speaks
 * S3. Credentials come from `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` and,
 * when set, `AWS_SESSION_TOKEN`, and from nowhere else: the configuration
 * provider reads no `~/.aws` file and no instance, container or web identity
 * role, so a self-hosted runner never lends the store its host's role. With
 * `AWS_ROLE_ARN` set, those keys assume that role. A key that is not there is
 * an empty ledger; one that cannot be fetched or read is unreadable, which
 * costs a run and never a verdict, and says why.
 */
final readonly class BucketLedger implements Configurable, ProofStore
{
    /** Where an object of the bucket is, as a run names it. */
    private const string AT = 's3://%s/%s';

    private const string UNWRITTEN = '%s could not be written: %s';


    private function __construct(
        private S3Client $client,
        private string $bucket,
        private LedgerObject $objects,
        private LedgerLimits $limits,
    ) {
    }

    public static function of(S3Client $client, string $bucket, string $prefix): self
    {
        return new self($client, $bucket, LedgerObject::under($prefix), LedgerLimits::standard());
    }

    /** Reading and writing ledgers within these limits. */
    public function within(LedgerLimits $limits): self
    {
        return new self($this->client, $this->bucket, $this->objects, $limits);
    }

    public static function fromOptions(Options $options): self|Invalid
    {
        $bucket = BucketOptions::read($options);

        return $bucket instanceof Invalid ? $bucket : self::over(HttpClient::create(), $bucket);
    }

    /**
     * The store these options locate, over this HTTP client, each request
     * allowed the seconds a ledger read over a network may take (ADR-0013).
     */
    public static function over(HttpClientInterface $http, BucketOptions $bucket): self
    {
        return self::of(
            new S3Client(
                $bucket->configuration(),
                new ConfigurationProvider(),
                $http->withOptions(['max_duration' => LedgerLimits::standard()->seconds()]),
            ),
            $bucket->bucket(),
            $bucket->prefix(),
        );
    }

    /** The scope's ledger; an empty one where there is none yet; or why the object could not be read. */
    public function read(Scope $scope): Ledger|Unreadable
    {
        $key = $this->objects->of($scope);

        if ($key instanceof CannotJudge) {
            return Ledger::empty();
        }

        $at = sprintf(self::AT, $this->bucket, $key);
        $fetched = $this->fetched($key, $at);
        $read = is_string($fetched) ? LedgerFile::read($fetched, $this->limits) : $fetched;

        return $read instanceof Ledger || $read instanceof Unreadable ? $read : Unreadable::notRead($at, $read);
    }

    public function write(Scope $scope, Ledger $ledger): Written|NotWritten
    {
        $key = $this->objects->of($scope);

        if ($key instanceof CannotJudge) {
            return NotWritten::because($key->why());
        }

        $encoded = EncodedLedger::within($ledger, $this->limits);
        $written = $this->put($key, $encoded->bytes());

        return $written instanceof Written ? $encoded->written($written) : $written;
    }

    /**
     * The bytes of an object kept beside the scope's ledger, within its
     * limits; none where there is no such key; or why it could not be read.
     */
    public function companion(Scope $scope, Companion $companion): Contents|Missing|CannotJudge
    {
        $key = $this->objects->companionOf($scope, $companion);

        if ($key instanceof CannotJudge) {
            return Missing::at(Path::of($companion->value));
        }

        $at = sprintf(self::AT, $this->bucket, $key);
        $fetched = $this->fetched($key, $at);
        $limits = CompanionRead::limitsOf($companion);

        return match (true) {
            is_string($fetched) && $limits->admitsPacked(Bytes::length($fetched)) => Contents::of($fetched),
            is_string($fetched) => CompanionRead::unread($companion, $at, $limits->pastPacked()),
            $fetched instanceof Unreadable => CompanionRead::unread($companion, $at, $fetched->detail()),
            default => Missing::at(Path::of($key)),
        };
    }

    public function keep(Scope $scope, Companion $companion, Contents $bytes): Written|NotWritten
    {
        $key = $this->objects->companionOf($scope, $companion);

        return $key instanceof CannotJudge ? NotWritten::because($key->why()) : $this->put($key, $bytes->text());
    }

    /** Put these bytes at a key, saying where, or why not. */
    private function put(string $key, string $bytes): Written|NotWritten
    {
        $at = sprintf(self::AT, $this->bucket, $key);

        try {
            $this->client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'Body' => $bytes,
                'ContentType' => 'application/gzip',
            ])->resolve();
        } catch (AwsFailure $failure) {
            return NotWritten::because(sprintf(self::UNWRITTEN, $at, $failure->getMessage()));
        }

        return Written::to($at);
    }

    /**
     * The object's bytes, read as they stream in and dropped once they pass
     * the limits, or refused before any is read where the object says it is
     * larger; an empty ledger where there is no such key; or why it could not
     * be read.
     */
    private function fetched(string $key, string $at): string|Ledger|Unreadable
    {
        try {
            $object = $this->client->getObject(['Bucket' => $this->bucket, 'Key' => $key]);
            $length = $object->getContentLength();

            return $length !== null && ! $this->limits->admitsPacked($length)
                ? Unreadable::because(UnreadReason::TooLarge, $at, $this->limits->saidPastPacked($length))
                : $this->streamed($object->getBody()->getChunks(), $at);
        } catch (AwsFailure $failure) {
            return match (true) {
                $failure instanceof NoSuchKeyException => Ledger::empty(),
                $failure instanceof HttpException => Unreadable::because(
                    UnreadReason::Refused,
                    $at,
                    sprintf('HTTP %d, %s', $failure->getCode(), $failure->getAwsCode() ?? 'no error code'),
                ),
                default => Unreadable::because(
                    UnreadReason::Unreachable,
                    $at,
                    Fit::line(Fit::plain($failure->getMessage()), Reply::ANSWER),
                ),
            };
        }
    }

    /**
     * The chunks of a body, joined; or, once they pass the limits, why they are not read.
     *
     * @param iterable<string> $chunks
     */
    private function streamed(iterable $chunks, string $at): string|Unreadable
    {
        $bytes = $this->limits->gathered($chunks);

        return $bytes instanceof TooLarge ? Unreadable::because(UnreadReason::TooLarge, $at, $bytes->why()) : $bytes;
    }
}
