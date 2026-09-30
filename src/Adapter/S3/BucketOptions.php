<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\S3;

use function array_filter;
use function array_values;

use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;

/**
 * What the `s3` store's options say, as the definition reads them with their
 * defaults: `bucket`, `prefix` and `region`, `auto` for R2, and the
 * `endpoint` where it is not AWS's own. A store at another endpoint, such as
 * R2 or MinIO, is addressed by path rather than by a host name per bucket.
 */
final readonly class BucketOptions
{
    private const string BUCKET = 'bucket';

    private const string PREFIX = 'prefix';

    private const string REGION = 'region';

    private const string ENDPOINT = 'endpoint';

    private function __construct(
        private string $bucket,
        private string $prefix,
        private string $region,
        private Endpoint|NotGiven $endpoint,
    ) {
    }

    public static function read(Options $options): self|Invalid
    {
        $bucket = self::required($options, self::BUCKET);
        $prefix = self::required($options, self::PREFIX);
        $region = self::required($options, self::REGION);
        $endpoint = $options->text(Key::of(self::ENDPOINT));

        if ($bucket instanceof Problem || $prefix instanceof Problem || $region instanceof Problem) {
            return Invalid::because(...self::problemsIn($bucket, $prefix, $region, $endpoint));
        }

        return $endpoint instanceof Problem
            ? Invalid::because($endpoint)
            : new self($bucket, $prefix, $region, $endpoint instanceof NotGiven ? $endpoint : Endpoint::at($endpoint));
    }

    public function bucket(): string
    {
        return $this->bucket;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    /** @return array{region: string, endpoint?: string, pathStyleEndpoint?: string} the client's configuration */
    public function configuration(): array
    {
        return [
            self::REGION => $this->region,
            ...$this->endpoint instanceof Endpoint
                ? [self::ENDPOINT => $this->endpoint->url(), 'pathStyleEndpoint' => 'true']
                : [],
        ];
    }

    /** @return list<Problem> */
    private static function problemsIn(string|Problem|NotGiven ...$answers): array
    {
        return array_values(array_filter(
            $answers,
            static fn(string|Problem|NotGiven $answer): bool => $answer instanceof Problem,
        ));
    }

    private static function required(Options $options, string $option): string|Problem
    {
        $value = $options->text(Key::of($option));

        return $value instanceof NotGiven
            ? Problem::at($option, sprintf('expected the %s, got nothing', $option))
            : $value;
    }
}
