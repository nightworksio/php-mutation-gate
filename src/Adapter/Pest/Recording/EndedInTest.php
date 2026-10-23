<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use RuntimeException;

use function sprintf;

/**
 * A mutant's own process ended as a test ran, and the plugin named that test
 * its killer. Thrown from the plugin's shutdown function and caught by
 * nothing, it ends the process with PHP's code of a fatal error, so Pest
 * counts the mutant tested however the process would have ended (ADR-0014).
 */
final class EndedInTest extends RuntimeException
{
    public static function in(string $test): self
    {
        return new self(sprintf('The process ended as %s ran, so it never passed.', $test));
    }
}
