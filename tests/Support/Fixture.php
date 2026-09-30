<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function basename;

/**
 * The smallest thing a rule must refuse, where it is planted, and the mark the
 * refusal must leave.
 */
final readonly class Fixture
{
    private function __construct(
        public string $rule,
        public Proof $proof,
        public string $path,
        public string $code,
        public string $marker,
        public string $evidence,
        public string $replacing,
    ) {
    }

    /** A file the analyser reads; `$marker` must appear in a message it reports for that file. */
    public static function analyser(string $rule, string $path, string $code, string $marker): self
    {
        return new self($rule, Proof::Analyser, $path, $code, $marker, $path, '');
    }

    /** A file the Arch suite reads; the test whose description holds `$marker` must fail and name the file. */
    public static function suite(string $rule, string $path, string $code, string $marker): self
    {
        return new self($rule, Proof::Suite, $path, $code, $marker, basename($path, '.php'), '');
    }

    /** A file the dependency analyser reads; its report must name the file. */
    public static function dependencies(string $rule, string $path, string $code): self
    {
        return new self($rule, Proof::Dependencies, $path, $code, '', $path, '');
    }

    /**
     * A change to a file the repository owns: `$replacing` is found exactly once
     * and replaced by `$code`, and the test holding `$marker` must fail saying
     * `$evidence`.
     */
    public static function edit(string $rule, string $path, string $replacing, string $code, string $marker, string $evidence): self
    {
        return new self($rule, Proof::Edit, $path, $code, $marker, $evidence, $replacing);
    }

    /**
     * A change to `composer.lock`: `$replacing` is found exactly once and
     * replaced by `$code`, and `composer audit --locked` over the copy must
     * report `$evidence`.
     */
    public static function audit(string $rule, string $replacing, string $code, string $evidence): self
    {
        return new self($rule, Proof::Audit, 'composer.lock', $code, '', $evidence, $replacing);
    }

    /**
     * A file planted alone in a copy of its own; the covered suite run there
     * must fall below its floor and list `$evidence` under 100%.
     */
    public static function coverage(string $rule, string $path, string $code, string $evidence): self
    {
        return new self($rule, Proof::Coverage, $path, $code, '', $evidence, '');
    }

    /** A rule whose judgement a test calls with the violation; `$how` names that test. */
    public static function direct(string $rule, string $how): self
    {
        return new self($rule, Proof::Direct, '', '', $how, '', '');
    }

    /** A rule no snippet can break, and why. */
    public static function notDrivable(string $rule, string $reason): self
    {
        return new self($rule, Proof::NotDrivable, '', '', $reason, '', '');
    }
}
