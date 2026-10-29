<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Analysis;

use function array_map;
use function implode;

use NightWorksIO\MutationGate\Core\Format\Fit;

use function sprintf;

/**
 * A file whose print, as its runner prints its mutants, analyses otherwise
 * than the file as written does where the print is read, so its mutants are
 * left to their tests (ADR-0020); with the findings only one of them has.
 */
final readonly class DifferingPrint
{
    /** How many characters of the findings that differ the run's warning shows. */
    private const int SHOWN = 250;

    private const string LINE = 'The print alone reports %s; %s alone reports %s';

    private const string NONE = 'no finding';

    private const string FINDING = '`%s` %s';

    private const string BETWEEN = ' | ';

    private function __construct(private string $detail)
    {
    }

    /** The print's findings against those of the file as written, or of the warm-up, which it named so. */
    public static function between(Findings $print, Findings $written, string $writtenNamed): self
    {
        $line = sprintf(
            self::LINE,
            self::listed($print->beyond($written)),
            $writtenNamed,
            self::listed($written->beyond($print)),
        );

        return new self(Fit::line($line, self::SHOWN));
    }

    /** Which findings differ, as the run's warning says it. */
    public function detail(): string
    {
        return $this->detail;
    }

    private static function listed(Findings $findings): string
    {
        $listed = array_map(
            static fn(Finding $finding): string => sprintf(self::FINDING, $finding->code(), $finding->message()),
            [...$findings],
        );

        return $listed === [] ? self::NONE : implode(self::BETWEEN, $listed);
    }
}
