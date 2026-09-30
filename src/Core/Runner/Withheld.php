<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function array_map;
use function array_unique;
use function array_values;

use ArrayIterator;

use function implode;

use Iterator;
use IteratorAggregate;

use function preg_quote;
use function sprintf;
use function str_replace;

/**
 * The environment variables a runner never hands the project's tests, and
 * so never any mutant of them: the CI's credentials, each a name or a glob
 * whose `*` stands for any run of characters. It only ever grows.
 *
 * @implements IteratorAggregate<int, string>
 */
final readonly class Withheld implements IteratorAggregate
{
    /** AWS's credentials, the Actions runtime's, GitHub's token and SonarCloud's. */
    private const array STANDARD = ['AWS_*', 'ACTIONS_*', 'GITHUB_TOKEN', 'SONAR_TOKEN'];

    /** @param list<string> $globs */
    private function __construct(private array $globs)
    {
    }

    /** What every run withholds, whatever the CI and the config. */
    public static function standard(): self
    {
        return new self(self::STANDARD);
    }

    public static function nothing(): self
    {
        return new self([]);
    }

    /** These names or globs, such as `CI_JOB_TOKEN` or `DEPLOY_*`. */
    public static function of(string ...$globs): self
    {
        return new self(array_values($globs));
    }

    /** @return Iterator<int, string> each name or glob, in the order given */
    public function getIterator(): Iterator
    {
        return new ArrayIterator($this->globs);
    }

    /** What both withhold. */
    public function and(self $other): self
    {
        return new self(array_values(array_unique([...$this->globs, ...$other->globs])));
    }

    /**
     * A PCRE pattern a withheld variable's whole name matches, and no other
     * name; where nothing is withheld, one that matches nothing.
     */
    public function pattern(): string
    {
        $alternatives = array_map(
            static fn(string $glob): string => str_replace('\*', '.*', preg_quote($glob, '~')),
            $this->globs,
        );

        return $alternatives === [] ? '~(?!)~' : sprintf('~^(?:%s)$~', implode('|', $alternatives));
    }
}
