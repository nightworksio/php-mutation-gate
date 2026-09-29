<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\S3;

use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Extension\Options;

use function sprintf;

/**
 * What the `s3` store's options say: `bucket`, which is required, `prefix`,
 * `mutation-gate` by default, `region`, `us-east-1` by default and `auto` for
 * R2, and `endpoint`, AWS's own by default. A store at another endpoint, such
 * as R2 or MinIO, is addressed by path rather than by a host name per bucket.
 */
final readonly class BucketOptions
{
    private const string BUCKET = 'bucket';

    private const string PREFIX = 'prefix';

    private const string REGION = 'region';

    private const string ENDPOINT = 'endpoint';

    /** Each option, and what it is where the config does not say. */
    private const array DEFAULTS = [
        self::BUCKET => '',
        self::PREFIX => 'mutation-gate',
        self::REGION => 'us-east-1',
        self::ENDPOINT => '',
    ];

    /** @param array<string, string> $options by name */
    private function __construct(private array $options)
    {
    }

    public static function read(Options $options): self|Invalid
    {
        $with = Node::decode($options->json());
        $read = [];
        $problems = [];

        foreach (self::DEFAULTS as $option => $otherwise) {
            $value = self::textOr($with, $option, $otherwise);
            $read[$option] = $value instanceof Problem ? $otherwise : $value;
            $problems = $value instanceof Problem ? [...$problems, $value] : $problems;
        }

        $problems = $read[self::BUCKET] === '' && $problems === []
            ? [Problem::at(self::BUCKET, 'The bucket the ledgers are kept in is required.')]
            : $problems;

        return $problems === [] ? new self($read) : Invalid::because(...$problems);
    }

    public function bucket(): string
    {
        return $this->options[self::BUCKET];
    }

    public function prefix(): string
    {
        return $this->options[self::PREFIX];
    }

    /** @return array<string, string> the client's configuration */
    public function configuration(): array
    {
        $endpoint = $this->options[self::ENDPOINT];

        return [
            self::REGION => $this->options[self::REGION],
            ...$endpoint === '' ? [] : [self::ENDPOINT => $endpoint, 'pathStyleEndpoint' => 'true'],
        ];
    }

    private static function textOr(Node $with, string $option, string $otherwise): string|Problem
    {
        try {
            return $with->field($option)->isPresent() ? $with->field($option)->text() : $otherwise;
        } catch (NotInShape) {
            return Problem::at($option, sprintf('The %s is written as text.', $option));
        }
    }
}
