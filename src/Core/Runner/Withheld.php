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
use NightWorksIO\MutationGate\Core\Proof\StoreVariable;
use NightWorksIO\MutationGate\Core\Test\Filter;

use function preg_quote;
use function sprintf;
use function str_replace;

/**
 * The environment variables a runner never hands the project's tests, and
 * so never any mutant of them: the CI's credentials and the secrets the gate
 * itself reads, each a name or a glob whose `*` stands for any run of
 * characters. It only ever grows.
 *
 * @implements IteratorAggregate<int, string>
 */
final readonly class Withheld implements IteratorAggregate
{
    /**
     * AWS's credentials, the Actions runtime's, GitHub's token and
     * SonarCloud's, and the Cloud Storage and Azure stores': the credentials
     * file `google-github-actions/auth` writes, under each name it exports
     * it by, Azure's variables, and each store's ready token.
     */
    private const array STANDARD = [
        'AWS_*',
        'ACTIONS_*',
        'GITHUB_TOKEN',
        'SONAR_TOKEN',
        StoreVariable::GoogleCredentials->value,
        'GOOGLE_GHA_CREDS_PATH',
        'CLOUDSDK_AUTH_CREDENTIAL_FILE_OVERRIDE',
        'AZURE_*',
        StoreVariable::GcsToken->value,
        StoreVariable::AzureToken->value,
    ];

    /**
     * What makes a process a worker or a mutant's run of another run, or has
     * a plugin act for the gate: the gate's own variables, Infection's,
     * pest-plugin-mutate's, paratest's and Laravel's parallel testing's.
     */
    private const array OTHER_RUNS = [
        'MUTATION_GATE_*',
        'INFECTION_*',
        'PEST_MUTATION_*',
        'PARATEST*',
        'TEST_TOKEN*',
        'UNIQUE_TEST_TOKEN*',
        'LARAVEL_PARALLEL_TESTING*',
    ];

    /** @param list<string> $globs */
    private function __construct(private array $globs)
    {
    }

    /** What every run withholds, whatever the CI and the config: those, and every secret the gate reads. */
    public static function standard(): self
    {
        return new self([
            ...self::STANDARD,
            ...array_map(static fn(GateSecret $secret): string => $secret->value, GateSecret::cases()),
        ]);
    }

    /**
     * What no process the gate starts may see: what every run withholds, the credentials of every CI the job may
     * run on, which withholding one that is not set costs nothing, and what the config's runner withholds.
     */
    public static function composed(self $runner, self ...$ciPlans): self
    {
        $withheld = self::standard();

        foreach ($ciPlans as $plan) {
            $withheld = $withheld->and($plan);
        }

        return $withheld->and($runner);
    }

    /**
     * The variables a process the gate starts never inherits from the gate,
     * whatever the config: those that would make it another run's worker or
     * mutant. What its command sets, it still gets.
     */
    public static function otherRuns(): self
    {
        return new self(self::OTHER_RUNS);
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

        return $alternatives === []
            ? sprintf('~%s~', Filter::nothing()->pattern())
            : sprintf('~^(?:%s)$~', implode('|', $alternatives));
    }
}
