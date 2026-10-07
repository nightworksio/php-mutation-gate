<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use function hash_copy;
use function hash_update;

use HashContext;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Test\TestId;

use function sprintf;

/**
 * The SHA-256 digest of the order a mutant's own run took its tests in, as
 * far as it went: each test's id, as the run names it, followed by a line
 * end, so a process can build it test by test as it runs and a runner that
 * knows the whole order gets the same digest at once.
 */
final readonly class OrderDigest
{
    private function __construct(private HashContext $context)
    {
    }

    /** The digest of an order that holds no test yet. */
    public static function start(): self
    {
        return new self(Digest::hashing());
    }

    /** The digest of these tests, in this order. */
    public static function of(TestId ...$tests): self
    {
        $digest = self::start();

        foreach ($tests as $test) {
            $digest = $digest->with($test);
        }

        return $digest;
    }

    /** This order, and one test more. */
    public function with(TestId $test): self
    {
        $context = hash_copy($this->context);
        hash_update($context, sprintf("%s\n", $test->value()));

        return new self($context);
    }

    /** The digest, in lowercase hex. */
    public function value(): string
    {
        return Digest::finished(hash_copy($this->context))->value();
    }
}
