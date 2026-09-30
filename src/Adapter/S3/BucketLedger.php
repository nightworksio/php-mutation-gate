<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\S3;

use AsyncAws\Core\Credentials\ConfigurationProvider;
use AsyncAws\Core\Exception\Exception as AwsFailure;
use AsyncAws\S3\S3Client;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\LedgerFile;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Configurable;
use NightWorksIO\MutationGate\Extension\Options;
use NightWorksIO\MutationGate\Port\ProofStore;

use function sprintf;
use function trim;

/**
 * The proof store `s3`: one object per scope, `<prefix>/<scope>/ledger.json.gz`,
 * in a bucket of AWS S3, Cloudflare R2, MinIO or anything else that speaks
 * S3. Credentials come from `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` and,
 * when set, `AWS_SESSION_TOKEN`. A ledger that cannot be fetched is read as
 * empty, which costs a run and never a verdict.
 */
final readonly class BucketLedger implements Configurable, ProofStore
{
    private const string FILE = 'ledger.json.gz';

    private function __construct(private S3Client $client, private string $bucket, private string $prefix)
    {
    }

    public static function of(S3Client $client, string $bucket, string $prefix): self
    {
        return new self($client, $bucket, trim($prefix, '/'));
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

    public function read(Scope $scope): Ledger
    {
        $key = $this->keyOf($scope);

        try {
            return $key instanceof CannotJudge ? Ledger::empty() : LedgerFile::decode($this->fetched($key));
        } catch (AwsFailure) {
            return Ledger::empty();
        }
    }

    public function write(Scope $scope, Ledger $ledger): Written|NotWritten
    {
        $key = $this->keyOf($scope);

        if ($key instanceof CannotJudge) {
            return NotWritten::because($key->why());
        }

        try {
            $this->client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'Body' => LedgerFile::encode($ledger),
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

        return Written::to(sprintf('s3://%s/%s', $this->bucket, $key));
    }

    /** @throws AwsFailure */
    private function fetched(string $key): string
    {
        return $this->client->getObject(['Bucket' => $this->bucket, 'Key' => $key])->getBody()->getContentAsString();
    }

    /** The object a scope's ledger is, for a scope that is a branch's or a pull request's ref and nothing else. */
    private function keyOf(Scope $scope): string|CannotJudge
    {
        $parsed = Scope::parse($scope->ref());

        return match (true) {
            $parsed instanceof CannotJudge => $parsed,
            $this->prefix === '' => sprintf('%s/%s', $parsed->ref(), self::FILE),
            default => sprintf('%s/%s/%s', $this->prefix, $parsed->ref(), self::FILE),
        };
    }
}
