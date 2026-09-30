<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Ci;

use function strtr;

/**
 * What `init --ci` fills into a CI definition's template (ADR-0015 decision
 * 16), each where the template writes `%%<name>%%`: the PHP version, the
 * default branch, the gate's commit and version, the runner and GitLab's
 * template file.
 */
final readonly class TemplateValues
{
    /** @param array<string, string> $values by the placeholder each replaces */
    private function __construct(private array $values)
    {
    }

    public static function of(
        string $php,
        string $branch,
        GatePin $gate,
        string $runner,
        string $template,
    ): self {
        return new self([
            '%%php%%' => $php,
            '%%branch%%' => $branch,
            '%%gate%%' => $gate->commit(),
            '%%version%%' => $gate->version(),
            '%%runner%%' => $runner,
            '%%template%%' => $template,
        ]);
    }

    /** A template, with each value in place of its placeholder. */
    public function rendered(string $template): string
    {
        return strtr($template, $this->values);
    }
}
