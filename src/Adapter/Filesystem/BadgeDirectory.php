<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Filesystem;

use function array_key_exists;

use DateInterval;

use function getenv;

use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Report\Badge;
use NightWorksIO\MutationGate\Core\Report\BadgeColors;
use NightWorksIO\MutationGate\Core\Report\Overview;
use NightWorksIO\MutationGate\Core\Report\SavingsText;
use NightWorksIO\MutationGate\Core\Report\Trend;
use NightWorksIO\MutationGate\Core\Report\TrendSvg;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Options;
use NightWorksIO\MutationGate\Port\Reporter;
use Psr\Clock\ClockInterface;

use function sprintf;

/**
 * The reporter `badge`: into `--publish-dir`, `badge.json` for shields.io,
 * the trend, `trend.json` with this verdict's entry appended to the one
 * found there and `trend.svg` drawn from it, and `savings.json`, what the
 * gate saved over the last 30 days (ADR-0017, decision 13). A run its budget cut short
 * updates neither (ADR-0009, decision 5). The command line runs it only for
 * a verdict in CI on the default branch.
 */
final readonly class BadgeDirectory implements Reporter
{
    /** Where the badge and trend are written unless `--publish-dir` says otherwise. */
    public const string PATH = '.mutation-gate/publish';

    public const string BADGE = 'badge.json';

    public const string TREND = 'trend.json';

    public const string SPARKLINE = 'trend.svg';

    public const string SAVINGS = 'savings.json';

    /** The variables CIs name the commit they run in, in the order they are asked. */
    private const array COMMITS = ['GITHUB_SHA', 'CI_COMMIT_SHA', 'BUILDKITE_COMMIT', 'CIRCLE_SHA1'];

    private const string CUT_SHORT = 'A run its budget cut short updates neither the badge nor the trend.';

    private function __construct(
        private ReportPath $path,
        private BadgeColors $colors,
        private string $commit,
        private ClockInterface $clock,
    ) {
    }

    public static function at(string $path, BadgeColors $colors, string $commit, ClockInterface $clock): self
    {
        return new self(ReportPath::at($path), $colors, $commit, $clock);
    }

    /**
     * From `path` (the publish directory), `colors` (`badge.colors`) and
     * `commit`, which is otherwise read from the CI's variables, dated by a clock.
     */
    public static function configured(Options $options, ClockInterface $clock): self|Invalid
    {
        $path = ReportPath::from($options, self::PATH, 'The badge and trend are written to a directory, as text.');
        $colors = self::colorsIn(Node::decode($options->json())->field('colors'));
        $commit = Node::decode($options->json())->field('commit');

        return match (true) {
            $path instanceof Invalid => $path,
            $colors instanceof Invalid => $colors,
            default => new self($path, $colors, self::commitFrom($commit), $clock),
        };
    }

    public function report(Verdict $verdict): Written|NotWritten
    {
        if ($verdict->wasCutShort()) {
            return NotWritten::because(self::CUT_SHORT);
        }

        $now = $this->clock->now();
        $trend = Trend::decode($this->path->file(self::TREND)->read())
            ->with($verdict, Revision::ref($this->commit), Instant::at($now));
        $since = Instant::at($now->sub(new DateInterval(sprintf('P%dD', SavingsText::DAYS))));
        $written = [
            $this->path->file(self::BADGE)->write(Badge::json(Overview::of($verdict)->score(), $this->colors)),
            $this->path->file(self::TREND)->write($trend->json()),
            $this->path->file(self::SPARKLINE)->write(TrendSvg::of($trend)),
            $this->path->file(self::SAVINGS)->write(Badge::savings($trend->savedSince($since))),
        ];

        foreach ($written as $answer) {
            if ($answer instanceof NotWritten) {
                return $answer;
            }
        }

        return Written::to($this->path->value());
    }

    private static function colorsIn(Node $colors): BadgeColors|Invalid
    {
        if (! $colors->isPresent()) {
            return BadgeColors::defaults();
        }

        try {
            $lowest = [];

            foreach ($colors->entries() as $color => $score) {
                $lowest[$color] = $score->number();
            }

            return BadgeColors::of($lowest);
        } catch (NotInShape) {
            return Invalid::because(Problem::at('colors', 'Each badge colour maps to the lowest score that earns it.'));
        }
    }

    private static function commitFrom(Node $commit): string
    {
        try {
            return $commit->isPresent() ? $commit->text() : self::commitOfCi(getenv());
        } catch (NotInShape) {
            return self::commitOfCi(getenv());
        }
    }

    /** @param array<string, string> $environment */
    private static function commitOfCi(array $environment): string
    {
        foreach (self::COMMITS as $variable) {
            if (array_key_exists($variable, $environment)) {
                return $environment[$variable];
            }
        }

        return '';
    }
}
