<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Filesystem;

use function array_key_exists;

use DateInterval;

use function getenv;
use function is_float;
use function is_string;

use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Report\Badge;
use NightWorksIO\MutationGate\Core\Report\BadgeColors;
use NightWorksIO\MutationGate\Core\Report\Overview;
use NightWorksIO\MutationGate\Core\Report\SavingsText;
use NightWorksIO\MutationGate\Core\Report\Summary;
use NightWorksIO\MutationGate\Core\Report\Trend;
use NightWorksIO\MutationGate\Core\Report\TrendSvg;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Percentage;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\Reporter;
use Psr\Clock\ClockInterface;

use function sprintf;

/**
 * The reporter `badge`: into `--publish-dir`, `badge.json` for shields.io,
 * the trend, `trend.json` with this verdict's entry appended to the one
 * found there and `trend.svg` drawn from it, `savings.json`, what the gate
 * saved over the last 30 days (ADR-0017), and `summary.json`, what an
 * organisation's dashboard reads (ADR-0024). A run its budget cut short
 * updates none of them (ADR-0009). The command line runs it only for
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

    public const string SUMMARY = 'summary.json';
    /** What `colors` holds, where it holds anything else. */
    private const string BANDS = 'Each badge colour maps to the lowest score that earns it.';

    /** What `newCode` holds, where it holds anything else. */
    private const string NEW_CODE = 'The floor for new code is a percentage from 0 to 100.';

    /** The variables CIs name the commit they run in, in the order they are asked. */
    private const array COMMITS = ['GITHUB_SHA', 'CI_COMMIT_SHA', 'BUILDKITE_COMMIT', 'CIRCLE_SHA1'];

    private const string CUT_SHORT = 'A run its budget cut short updates neither the badge nor the trend.';

    private const string SAMPLED = 'A sampled run updates neither the badge nor the trend.';

    private function __construct(
        private ReportPath $path,
        private BadgeColors $colors,
        private string $commit,
        private ClockInterface $clock,
        private Floor $newCode,
    ) {
    }

    /** Into a directory, with the standard floor for new code. */
    public static function at(string $path, BadgeColors $colors, string $commit, ClockInterface $clock): self
    {
        return new self(ReportPath::at($path), $colors, $commit, $clock, Floor::whole());
    }

    /** This reporter, with this floor for new code in the summary. */
    public function holdingNewCodeTo(Floor $newCode): self
    {
        return clone($this, ['newCode' => $newCode]);
    }

    /**
     * From `path` (the publish directory), `colors` (`badge.colors`),
     * `newCode` (`floors.newCode`, whole where it is not given) and `commit`,
     * which is otherwise read from the CI's variables, dated by a clock.
     */
    public static function configured(Options $options, ClockInterface $clock): self|Invalid
    {
        $path = ReportPath::namedOr($options, self::PATH);
        $colors = self::colorsIn($options);
        $newCode = self::newCodeIn($options);
        $commit = $options->text(Key::of('commit'));

        return match (true) {
            $path instanceof Invalid => $path,
            $colors instanceof Invalid => $colors,
            $newCode instanceof Invalid => $newCode,
            default => new self(
                $path,
                $colors,
                is_string($commit) ? $commit : self::commitOfCi(getenv()),
                $clock,
                $newCode,
            ),
        };
    }

    public function report(Verdict $verdict): Written|NotWritten
    {
        $unwritten = match (true) {
            $verdict->wasCutShort() => NotWritten::because(self::CUT_SHORT),
            $verdict->trees()->sample()->isDrawn() => NotWritten::because(self::SAMPLED),
            default => NotGiven::value(),
        };

        if ($unwritten instanceof NotWritten) {
            return $unwritten;
        }

        $now = $this->clock->now();
        $trend = Trend::decode($this->path->file(self::TREND)->read())
            ->with($verdict, Revision::ref($this->commit), Instant::at($now));
        $since = Instant::at($now->sub(new DateInterval(sprintf('P%dD', SavingsText::DAYS))));
        $saved = $trend->savedSince($since);
        $summary = Summary::json($verdict, Revision::ref($this->commit), Instant::at($now), $this->newCode, $saved);
        $written = [
            $this->path->file(self::BADGE)->write(Badge::json(Overview::of($verdict)->score(), $this->colors)),
            $this->path->file(self::TREND)->write($trend->json()),
            $this->path->file(self::SPARKLINE)->write(TrendSvg::of($trend)),
            $this->path->file(self::SAVINGS)->write(Badge::savings($saved)),
            $this->path->file(self::SUMMARY)->write($summary),
        ];

        foreach ($written as $answer) {
            if ($answer instanceof NotWritten) {
                return $answer;
            }
        }

        return Written::to($this->path->value());
    }

    private static function colorsIn(Options $options): BadgeColors|Invalid
    {
        $colors = $options->object(Key::of('colors'));

        if (! $colors instanceof Options) {
            return $colors instanceof NotGiven
                ? BadgeColors::defaults()
                : Invalid::because(Problem::at('colors', self::BANDS));
        }

        $lowest = [];

        foreach ($colors as $color) {
            $score = $colors->number($color);

            if (! is_float($score)) {
                return Invalid::because(Problem::at('colors', self::BANDS));
            }

            $lowest[$color->value()] = $score;
        }

        return BadgeColors::of($lowest);
    }

    private static function newCodeIn(Options $options): Floor|Invalid
    {
        $newCode = $options->number(Key::of('newCode'));
        $floor = is_float($newCode) ? Percentage::parse($newCode) : $newCode;

        return match (true) {
            $floor instanceof Percentage => Floor::ofHundredths($floor->hundredths()),
            $floor instanceof NotGiven => Floor::whole(),
            default => Invalid::because(Problem::at('newCode', self::NEW_CODE)),
        };
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
