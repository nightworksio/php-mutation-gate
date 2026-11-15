<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Filesystem;

use function getenv;
use function is_float;
use function is_int;
use function is_string;

use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\CiRun;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Cost\SavingsWindow;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Report\Badge;
use NightWorksIO\MutationGate\Core\Report\BadgeColors;
use NightWorksIO\MutationGate\Core\Report\BadgeLogo;
use NightWorksIO\MutationGate\Core\Report\Overview;
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

/**
 * The reporter `badge`: into `--publish-dir`, `badge.json` for shields.io,
 * with the logo `logo` names, the trend, `trend.json` with this verdict's
 * entry appended to the one found there and `trend.svg` drawn from it,
 * `savings.json`, what the gate saved in `costs.savingsWindow`, 30 days
 * unless set (ADR-0017), and `summary.json`, what an organisation's
 * dashboard reads (ADR-0024). A run its budget cut short updates none of
 * them (ADR-0009). The command line runs it only for a verdict in CI on the
 * default branch.
 *
 * @internal
 */
final readonly class BadgeDirectory implements Reporter
{
    /** Where the badge and trend are written unless `--publish-dir` says otherwise. */
    public const string PATH = '.mutation-gate/publish';

    public const string BADGE = 'badge.json';

    public const string TREND = Trend::FILE;

    public const string SPARKLINE = 'trend.svg';

    public const string SAVINGS = 'savings.json';

    public const string SUMMARY = Summary::FILE;
    /** What `colors` holds, where it holds anything else. */
    private const string BANDS = 'Each badge colour maps to the lowest score that earns it.';

    /** What `newCode` holds, where it holds anything else. */
    private const string NEW_CODE = 'The floor for new code is a percentage from 0 to 100.';

    /** What `logo` holds, where it holds anything else. */
    private const string LOGO = 'The badge\'s logo is true for the helix, false for none, or the path of an SVG file.';

    /** What a `logo` file holds, where it holds nothing. */
    private const string NO_LOGO = 'The badge\'s logo names a file that holds no SVG.';

    private const string CUT_SHORT = 'A run its budget cut short updates neither the badge nor the trend.';

    private const string SAMPLED = 'A sampled run updates neither the badge nor the trend.';

    private function __construct(
        private ReportPath $path,
        private BadgeColors $colors,
        private string $commit,
        private ClockInterface $clock,
        private Floor $newCode,
        private SavingsWindow $window,
        private int $kept,
        private BadgeLogo $logo,
    ) {
    }

    /**
     * Into a directory, with the standard floor for new code, the standard
     * savings window and the gate's helix as the logo.
     */
    public static function at(string $path, BadgeColors $colors, string $commit, ClockInterface $clock): self
    {
        return new self(
            ReportPath::at($path),
            $colors,
            $commit,
            $clock,
            Floor::whole(),
            SavingsWindow::standard(),
            Trend::KEPT,
            self::helix(),
        );
    }

    /** The gate's own logo, the helix the package ships. */
    public static function helix(): BadgeLogo
    {
        $helix = ReportPath::at(Resources::at(Shipped::Badge))->file(BadgeLogo::HELIX);

        return BadgeLogo::drawn($helix->read());
    }

    /** This reporter, with this floor for new code in the summary. */
    public function holdingNewCodeTo(Floor $newCode): self
    {
        return new self(
            $this->path,
            $this->colors,
            $this->commit,
            $this->clock,
            $newCode,
            $this->window,
            $this->kept,
            $this->logo,
        );
    }

    /** This reporter, with this logo on its badges. */
    public function showing(BadgeLogo $logo): self
    {
        return new self(
            $this->path,
            $this->colors,
            $this->commit,
            $this->clock,
            $this->newCode,
            $this->window,
            $this->kept,
            $logo,
        );
    }

    /**
     * From `path` (the publish directory), `colors` (`badge.colors`),
     * `logo` (`badge.logo`, the helix where it is not given), `newCode`
     * (`floors.newCode`, whole where it is not given), `savingsWindow`
     * (`costs.savingsWindow`), `trendKept`, the newest entries the trend
     * keeps, and `commit`, which is otherwise read from the CI's variables,
     * dated by a clock.
     */
    public static function configured(Options $options, ClockInterface $clock): self|Invalid
    {
        $path = ReportPath::namedOr($options, self::PATH);
        $colors = self::colorsIn($options);
        $newCode = self::newCodeIn($options);
        $logo = self::logoIn($options);
        $commit = $options->text(Key::of('commit'));

        return match (true) {
            $path instanceof Invalid => $path,
            $colors instanceof Invalid => $colors,
            $newCode instanceof Invalid => $newCode,
            $logo instanceof Invalid => $logo,
            default => new self(
                $path,
                $colors,
                is_string($commit) ? $commit : self::commitOfCi(Variables::of(getenv())),
                $clock,
                $newCode,
                SavingsWindow::in($options),
                self::keptIn($options),
                $logo,
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
        $trend = Trend::decode($this->path->file(self::TREND)->read(), $this->kept)
            ->with($verdict, Revision::ref($this->commit), Instant::at($now));
        $saved = $this->window->saved($trend->savedSince($this->window->since($now)));
        $summary = Summary::json($verdict, Revision::ref($this->commit), Instant::at($now), $this->newCode, $saved);
        $badge = Badge::json(Overview::of($verdict)->score(), $this->colors, $this->logo);
        $written = [
            $this->path->file(self::BADGE)->write($badge),
            $this->path->file(self::TREND)->write($trend->json()),
            $this->path->file(self::SPARKLINE)->write(TrendSvg::of($trend)),
            $this->path->file(self::SAVINGS)->write(Badge::savings($saved, $this->logo)),
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

    private static function logoIn(Options $options): BadgeLogo|Invalid
    {
        $shown = $options->flag(Key::of('logo'));

        return match (true) {
            $shown instanceof NotGiven, $shown === true => self::helix(),
            $shown === false => BadgeLogo::none(),
            default => self::logoFileIn($options),
        };
    }

    private static function logoFileIn(Options $options): BadgeLogo|Invalid
    {
        $file = $options->path(Key::of('logo'));

        return $file instanceof Path ? self::logoAt($file) : Invalid::because(Problem::at('logo', self::LOGO));
    }

    private static function logoAt(Path $file): BadgeLogo|Invalid
    {
        $svg = ReportPath::at($file->value())->read();

        return $svg === '' ? Invalid::because(Problem::at('logo', self::NO_LOGO)) : BadgeLogo::drawn($svg);
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

    /** How many of the newest entries the trend keeps: `trendKept`, which the definition checked, or 500. */
    private static function keptIn(Options $options): int
    {
        $kept = $options->integer(Key::of(Trend::KEPT_OPTION));

        return is_int($kept) ? $kept : Trend::KEPT;
    }

    /** The commit the CI's job runs in, as each CI names it; none outside a CI. */
    private static function commitOfCi(Variables $environment): string
    {
        $run = CiRun::read($environment);

        return $run instanceof CiRun ? $run->commit() : '';
    }
}
