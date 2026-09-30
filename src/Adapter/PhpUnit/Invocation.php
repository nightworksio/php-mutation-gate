<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * PHPUnit, started for one mutant (ADR-0023 decision 9): opcache off, so no
 * cached original runs in the mutated file's place and no mutated file is
 * cached for a later run; the override prepended; the extension recording
 * each test; only the covering tests selected by their ids; and stopped at
 * the first test that fails or errors, and not at one that is only risky or
 * warns.
 */
final readonly class Invocation
{
    public function __construct(private Project $project, private string $override)
    {
    }

    public function of(
        MutantFiles $files,
        WholeSuite|Group|Filter $judgedBy,
        Seconds $limit,
        Withheld $withheld,
    ): Command {
        return Command::php(
            '-d',
            'opcache.enable_cli=0',
            '-d',
            sprintf('auto_prepend_file=%s', $this->override),
            $this->project->phpunit(),
            '--extension',
            Extension::class,
            sprintf('--test-id-filter-file=%s', $files->ids()),
            '--stop-on-error',
            '--stop-on-failure',
            '--no-progress',
            ...$this->judgedBy($judgedBy),
        )
            ->telling(Variable::Results, $files->results())
            ->telling(Variable::Guard, $files->guard())
            ->telling(Variable::Mutant, $files->original())
            ->telling(Variable::Mutated, $files->mutated())
            ->withholding($withheld)
            ->within($limit);
    }

    /** @return list<string> the options that keep a run to the tests that judge the unit */
    private function judgedBy(WholeSuite|Group|Filter $judgedBy): array
    {
        return match (true) {
            $judgedBy instanceof Group => ['--group', $judgedBy->name()],
            $judgedBy instanceof Filter => ['--filter', $judgedBy->pattern()],
            default => [],
        };
    }
}
