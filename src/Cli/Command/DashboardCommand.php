<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function array_filter;
use function array_values;
use function count;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Filesystem\HtmlReportDirectory;
use NightWorksIO\MutationGate\Adapter\Filesystem\ReportPath;
use NightWorksIO\MutationGate\Adapter\Filesystem\Resources;
use NightWorksIO\MutationGate\Adapter\Filesystem\Shipped;
use NightWorksIO\MutationGate\Adapter\GitHub\Api;
use NightWorksIO\MutationGate\Adapter\GitHub\Organisation;
use NightWorksIO\MutationGate\Cli\CommandLine;
use NightWorksIO\MutationGate\Cli\Config\Effective;
use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Publishing;
use NightWorksIO\MutationGate\Core\Dashboard\Dashboard;
use NightWorksIO\MutationGate\Core\Dashboard\DashboardPage;
use NightWorksIO\MutationGate\Core\Dashboard\Listing;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Time\Instant;
use Psr\Clock\ClockInterface;

use function sprintf;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * `dashboard --org=<org> --out=<dir> [--include-private]`: an organisation's
 * dashboard, built from what each of its repositories published (ADR-0024),
 * as `index.html` and `dashboard.json` in a directory. It reads through
 * GitHub's API with `GITHUB_TOKEN`, the repositories with `dashboard.topic`
 * or those `dashboard.repositories` names, and leaves out every private one
 * unless asked to show them, which it does only for the private Pages site
 * of the repository it runs in, `GITHUB_REPOSITORY`. It exits 0 once it
 * wrote both files, and 2 where it was not told where, cannot list the
 * repositories, may not show private ones, or cannot write.
 */
final readonly class DashboardCommand
{
    public const string PAGE = HtmlReportDirectory::PAGE;

    public const string DATA = 'dashboard.json';

    private const string ORG = 'org';

    private const string OUT = 'out';

    private const string PRIVATE = 'include-private';

    private const string UNTOLD = 'dashboard builds a site for --org=<organisation> into --out=<directory>.';

    private const string PUBLIC_SITE
        = '--include-private is for a private Pages site, and GitHub does not say the site of %s is private.';

    private const string UNLISTED = 'GitHub cannot list the repositories of %s: %s';

    private const string WROTE = 'Wrote %s: %d %s.';

    public static function command(
        Effective $effective,
        Variables $environment,
        HttpClientInterface $client,
        ClockInterface $clock,
    ): Command {
        return new Command('dashboard')
            ->setDescription('Build an organisation\'s dashboard from what each repository published')
            ->addOption(self::ORG, mode: InputOption::VALUE_REQUIRED, description: 'The GitHub organisation')
            ->addOption(self::OUT, mode: InputOption::VALUE_REQUIRED, description: 'Where it writes the site')
            ->addOption(self::PRIVATE, mode: InputOption::VALUE_NONE, description: 'Show private repositories too')
            ->setCode(static function (InputInterface $input, OutputInterface $output) use (
                $effective,
                $environment,
                $client,
                $clock,
            ): int {
                $org = $input->getOption(self::ORG);
                $out = $input->getOption(self::OUT);
                $publishing = $effective->publishing(CommandLine::from($input));

                if (! is_string($org) || $org === '' || ! is_string($out) || $out === '') {
                    return Failed::because($output, CannotJudge::because(self::UNTOLD));
                }

                if (! $publishing instanceof Publishing) {
                    return Failed::because($output, $publishing);
                }

                $organisation = Organisation::of(
                    Api::at($client, $environment->valueOf('GITHUB_API_URL'), $environment->valueOf('GITHUB_TOKEN')),
                    $org,
                );
                $built = self::built(
                    $organisation,
                    $org,
                    $publishing,
                    $input->getOption(self::PRIVATE) === true,
                    $environment->valueOf('GITHUB_REPOSITORY'),
                    Instant::at($clock->now()),
                );

                return $built instanceof Dashboard
                    ? self::written($output, ReportPath::at($out), $built)
                    : Failed::because($output, $built);
            });
    }

    private static function built(
        Organisation $organisation,
        string $org,
        Publishing $publishing,
        bool $private,
        string $site,
        Instant $now,
    ): Dashboard|CannotJudge {
        if ($private && ! $organisation->pagesArePrivate($site)) {
            return CannotJudge::because(sprintf(self::PUBLIC_SITE, $site));
        }

        $listings = $organisation->listings($publishing->topic(), $publishing->repositories());

        if ($listings instanceof CannotTell) {
            return CannotJudge::because(sprintf(self::UNLISTED, $org, $listings->why()));
        }

        $shown = array_values(array_filter(
            $listings,
            static fn(Listing $listing): bool => $private || ! $listing->isPrivate(),
        ));

        return Dashboard::of($org, $now, ...$shown);
    }

    private static function written(OutputInterface $output, ReportPath $out, Dashboard $dashboard): int
    {
        $page = ReportPath::at(Resources::at(Shipped::DashboardPage));
        $written = [
            $out->file(self::DATA)->write($dashboard->json()),
            $out->file(self::PAGE)->write(DashboardPage::html(
                $dashboard,
                $page->file('page.css')->read(),
                $page->file('page.js')->read(),
            )),
        ];

        foreach ($written as $answer) {
            if ($answer instanceof NotWritten) {
                return Failed::because($output, CannotJudge::because($answer->why()));
            }
        }

        $count = count($dashboard->listings());
        $output->writeln(sprintf(self::WROTE, $out->value(), $count, $count === 1 ? 'repository' : 'repositories'));

        return ExitCode::Passed->value;
    }
}
