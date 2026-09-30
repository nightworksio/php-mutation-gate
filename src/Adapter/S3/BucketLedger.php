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
use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\Http\Reply;
use NightWorksIO\MutationGate\Core\NotWritten;
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

        return $bucket instanceof Invalid ? $bucket : self::of(
            new S3Client($bucket->configuration(), new ConfigurationProvider()),
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

        $at = sprintf('s3://%s/%s', $this->bucket, $key);
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

        try {
            $this->client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'Body' => $encoded->bytes(),
                'ContentType' => 'application/gzip',
            ])->resolve();
        } catch (AwsFailure $failure) {
            return NotWritten::because(sprintf(
                's3://%s/%s could not be written: %s',
                $this->bucket,
                $key,
                $failure->getMessage(),
            ));
        }

        return $encoded->written(Written::to(sprintf('s3://%s/%s', $this->bucket, $key)));
    }

    /** The object's bytes; an empty ledger where there is no such key; or why it could not be read. */
    private function fetched(string $key, string $at): string|Ledger|Unreadable
    {
        try {
            $object = $this->client->getObject(['Bucket' => $this->bucket, 'Key' => $key]);

            return $object->getBody()->getContentAsString();
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
}
