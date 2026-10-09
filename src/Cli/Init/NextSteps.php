<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Init;

use function array_diff;
use function array_map;
use function array_values;
use function explode;
use function implode;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Git\Command as Git;
use NightWorksIO\MutationGate\Cli\ComposerVendor;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\ShellWord;
use NightWorksIO\MutationGate\Core\ThisPackage;

use function sort;
use function sprintf;

/**
 * What `init` says to do once it has set the project up (ADR-0017 decision
 * 1, ADR-0024 decision 11): `doctor`, the first run, and `git add` of the
 * files it wrote; and, where a person asked for `composer mutate`, the two
 * Composer commands that add the plugin, and the root script that needs
 * none.
 */
final readonly class NextSteps
{
    /** Every file git would add from the project: changed, or new and not ignored. */
    private const array LISTED = ['ls-files', '-z', '--modified', '--others', '--exclude-standard'];

    private const string NEXT = "Next:\n  %s doctor\n  %s";

    private const string ADD = "%s\n  git add %s";

    private const string PLUGIN = <<<'SAID'
        To add composer mutate, from the optional Composer plugin:
          composer config allow-plugins.nightworksio/mutation-gate-composer true
          composer require --dev nightworksio/mutation-gate-composer
        Or, with no plugin, add this to the scripts in composer.json:
          "mutate": ["Composer\\Config::disableProcessTimeout", "mutation-gate"]
        SAID;

    /** @param list<string> $before the files git would add before `init` wrote anything */
    private function __construct(private string $project, private array $before)
    {
    }

    /** The project as it is before `init` writes anything. */
    public static function before(string $project): self
    {
        return new self($project, self::listed($project));
    }

    /** The commands to run next; `git add` of the files `init` wrote, where it wrote any git would add. */
    public function said(): string
    {
        $gate = ComposerVendor::binaries($this->project)->child(Path::of(ThisPackage::NAME))->value();
        $next = sprintf(self::NEXT, ShellWord::of($gate), ShellWord::of($gate));
        $written = array_values(array_diff(self::listed($this->project), $this->before));
        sort($written);

        return $written === []
            ? $next
            : sprintf(self::ADD, $next, implode(' ', array_map(ShellWord::of(...), $written)));
    }

    /** How to add `composer mutate`. */
    public static function plugin(): string
    {
        return self::PLUGIN;
    }

    /** @return list<string> the files git would add, as the project names them; none outside a repository */
    private static function listed(string $project): array
    {
        $listed = Git::in($project)->run(self::LISTED);

        return is_string($listed) ? array_values(array_diff(explode("\0", $listed), [''])) : [];
    }
}
