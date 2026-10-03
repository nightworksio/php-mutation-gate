<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Ci;

use function implode;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\BuiltinStore;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;

use function preg_match;
use function sprintf;
use function strtr;

/**
 * What `init --ci` fills into a CI definition's template (ADR-0015 decision
 * 16), each where the template writes `%%<name>%%`: the PHP version, the
 * default branch, the gate's pin and version, the runner, the check to
 * require, the file of the gate's jobs the CI's own definition pulls in,
 * where it has one, the variables the S3 store reads, and what holds the
 * proof store's keys on Bitbucket and Jenkins. A template lands in YAML,
 * Groovy and shell lines, so each value that comes from the project holds no
 * character any of them would read as more than text.
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
        string $check,
        Path|NotGiven $included,
    ): self|CannotJudge {
        $gitlabFix = 'Set ci.gitlab.template to a path of letters, digits and ._/-.';
        $checked = [
            ['The default branch', $branch, self::NAME, 'Set ci.defaultBranch to a name of letters, digits and ._/-.'],
            ['The runner', $runner, self::RUNNER, 'Choose a runner by a name of letters, digits and ._-.'],
            ['The check', $check, self::CHECK, 'Set ci.check to a name of letters, digits, spaces and ._/-.'],
            ...$included instanceof Path
                ? [['The file', $included->value(), self::NAME, $gitlabFix]]
                : [],
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
            '%%check%%' => $check,
            '%%s3%%' => implode(' ', [...BuiltinStore::S3->variables()]),
            '%%keys%%' => CiTemplate::keyHolder(),
            ...$included instanceof Path ? ['%%included%%' => $included->value()] : [],
        ]);
    }

    /** A template, with each value in place of its placeholder. */
    public function rendered(string $template): string
    {
        return strtr($template, $this->values);
    }
}
