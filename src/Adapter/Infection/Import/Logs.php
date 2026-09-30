<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection\Import;

use NightWorksIO\MutationGate\Core\Config\BuiltinReporter;
use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Report;
use NightWorksIO\MutationGate\Core\Config\Reports;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Import\Carried;
use NightWorksIO\MutationGate\Core\Import\Import;

use function sprintf;

/**
 * Infection's `logs` as the gate's `reports` (ADR-0016, decision 1): its
 * HTML report, or the Stryker dashboard's, as the gate's HTML report, and
 * its GitLab Code Quality file as the gate's. The gate writes every other
 * log of Infection's itself, in the config it generates for each run.
 */
final readonly class Logs
{
    private const string KEY = 'logs.%s';

    private const string REPORT = 'a reports entry %s at %s';

    private const string STRYKER = 'stryker';

    private const string GENERATED
        = 'the gate writes Infection\'s logs itself, in the config it generates for each run';

    private const string GITHUB = 'annotations are automatic under GitHub Actions';

    private const string BADGE = 'the gate publishes its own badge';

    private const string HTML_GIVEN = 'logs.html gives the HTML report already';

    public static function of(Node $settings): Import
    {
        $logs = $settings->field(Mapped::Logs->value);
        $import = Import::none();

        foreach (Lenient::entries($logs) as $key => $value) {
            $import = $import->and(self::log(sprintf('%s', $key), $value, $logs));
        }

        return $import;
    }

    private static function log(string $key, Node $value, Node $logs): Import
    {
        $path = Lenient::text($value);
        $html = BuiltinReporter::Html->value;
        $gitlab = BuiltinReporter::GitLab->value;

        return match (true) {
            $key === $html && $path !== '' => self::report($key, $html, Choices::html(Path::of($path))),
            $key === $gitlab && $path !== '' => self::report($key, $gitlab, Path::of($path)),
            $key === 'json' && $path !== '' => self::dropped($key, Choices::json(Path::of($path))),
            $key === 'github' => self::dropped($key, self::GITHUB),
            $key === self::STRYKER => self::stryker($value, $logs),
            default => self::dropped($key, self::GENERATED),
        };
    }

    /** The Stryker dashboard's report as the gate's HTML report, where no `logs.html` gives one; its badge is not. */
    private static function stryker(Node $stryker, Node $logs): Import
    {
        $import = Import::none();
        $html = BuiltinReporter::Html->value;

        foreach (Lenient::entries($stryker) as $key => $value) {
            $key = sprintf('%s.%s', self::STRYKER, $key);
            $import = $import->and(match (true) {
                $key === 'stryker.report' && Lenient::text($logs->field($html)) === '' => self::report(
                    $key,
                    $html,
                    Choices::dashboard(),
                ),
                $key === 'stryker.report' => self::dropped($key, self::HTML_GIVEN),
                $key === 'stryker.badge' => self::dropped($key, self::BADGE),
                default => self::dropped($key, self::GENERATED),
            });
        }

        return $import;
    }

    private static function report(string $key, string $reporter, Path $path): Import
    {
        return Import::of(
            Layer::of(Reports::of(Listed::of(Report::of(Choice::of($reporter, Options::none()), $path)))),
            Carried::imported(sprintf(self::KEY, $key), sprintf(self::REPORT, $reporter, $path->value())),
        );
    }

    private static function dropped(string $key, string $because): Import
    {
        return Import::of(Layer::none(), Carried::dropped(sprintf(self::KEY, $key), $because));
    }
}
