<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Ci;

use NightWorksIO\MutationGate\Core\CannotJudge;

use function preg_match;
use function sprintf;
use function strtr;

/**
 * What `init --ci` fills into a CI definition's template (ADR-0015 decision
 * 16), each where the template writes `%%<name>%%`: the PHP version, the
 * default branch, the gate's pin and version, the runner, GitLab's template
 * file, the check to require and the pipeline Buildkite uploads. A template
 * lands in YAML and in shell lines, so each value that comes from the project
 * holds no character either would read as more than text.
 */
final readonly class TemplateValues
{
    /** A branch or a file: letters, digits, and `.`, `_`, `/` and `-`. */
    private const string NAME = '#^[A-Za-z0-9._/-]+$#D';

    /** A check-run's name, which may also hold spaces. */
    private const string CHECK = '#^[A-Za-z0-9 ._/-]+$#D';

    /** A runner, by its name or by a class, whose namespace is spelt with backslashes. */
    private const string RUNNER = '#^[A-Za-z0-9._\\\\-]+$#D';

    private const string UNSAFE = '%s "%s" cannot go into a CI definition, where a shell or YAML reads it as code. %s';

    /** @param array<string, string> $values by the placeholder each replaces */
    private function __construct(private array $values)
    {
    }

    /** These values, or why one of them cannot be written into a template. */
    public static function of(
        string $php,
        string $branch,
        GatePin $gate,
        string $runner,
        string $template,
        string $check,
        string $pipeline,
    ): self|CannotJudge {
        $checked = [
            ['The default branch', $branch, self::NAME, 'Set ci.defaultBranch to a name of letters, digits and ._/-.'],
            ['The runner', $runner, self::RUNNER, 'Choose a runner by a name of letters, digits and ._-.'],
            ['The file', $template, self::NAME, 'Set ci.gitlab.template to a path of letters, digits and ._/-.'],
            ['The check', $check, self::CHECK, 'Set ci.check to a name of letters, digits, spaces and ._/-.'],
        ];

        foreach ($checked as [$what, $value, $pattern, $fix]) {
            if (preg_match($pattern, $value) !== 1) {
                return CannotJudge::because(sprintf(self::UNSAFE, $what, $value, $fix));
            }
        }

        return new self([
            '%%php%%' => $php,
            '%%branch%%' => $branch,
            '%%pin%%' => $gate->pin(),
            '%%version%%' => $gate->version(),
            '%%runner%%' => $runner,
            '%%template%%' => $template,
            '%%check%%' => $check,
            '%%pipeline%%' => $pipeline,
        ]);
    }

    /** A template, with each value in place of its placeholder. */
    public function rendered(string $template): string
    {
        return strtr($template, $this->values);
    }
}
