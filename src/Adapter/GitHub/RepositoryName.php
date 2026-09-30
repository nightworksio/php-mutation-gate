<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use NightWorksIO\MutationGate\Core\Change\CannotTell;

use function preg_match;
use function preg_quote;
use function sprintf;

/**
 * A repository on GitHub by its `owner/name`: as `GITHUB_REPOSITORY` names
 * it, or as a git remote's URL on GitHub's host does, over HTTPS or SSH.
 * Nothing else is taken, so the name can stand in an API path as it is.
 */
final readonly class RepositoryName
{
    /** An owner, as GitHub allows one, and a repository's name other than `.` or `..`. */
    private const string NAME = '[A-Za-z0-9-]+/(?!\.\.?(?:\.git)?/?$)[A-Za-z0-9_.-]+';

    private const string NOT_A_NAME = '%s is no repository name of the form owner/name.';

    private const string NOT_ON_GITHUB = 'git\'s origin remote, %s, is not a repository on %s.';

    private function __construct(private string $value)
    {
    }

    public static function of(string $name): self|CannotTell
    {
        return preg_match(sprintf('~^%s$~', self::NAME), $name) === 1
            ? new self($name)
            : CannotTell::because(sprintf(self::NOT_A_NAME, $name));
    }

    /** The repository a remote's URL names on this host, such as `github.com`. */
    public static function fromRemote(string $url, string $host): self|CannotTell
    {
        $pattern = sprintf(
            '~^(?:https://|ssh://git@|git@)%s[:/](%s?)(?:\.git)?/?$~',
            preg_quote($host, '~'),
            self::NAME,
        );

        return preg_match($pattern, $url, $matched) === 1
            ? new self($matched[1])
            : CannotTell::because(sprintf(self::NOT_ON_GITHUB, $url, $host));
    }

    public function value(): string
    {
        return $this->value;
    }
}
