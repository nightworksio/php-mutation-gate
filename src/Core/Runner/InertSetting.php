<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

/**
 * An ini setting a platform's digest leaves out, because it only decides how
 * an error is shown or logged, or how PHP's interactive shell looks, and
 * never what a test computes or whether it finishes. Every setting not named
 * here is in the digest, including one PHP or an extension adds later.
 */
enum InertSetting: string
{
    /** Whether a raised error is printed; `error_reporting`, which decides whether it is raised, is in the digest. */
    case DisplayErrors = 'display_errors';

    /** Whether an error raised while PHP starts is printed, which happens before any test runs. */
    case DisplayStartupErrors = 'display_startup_errors';

    /** Whether a printed error is marked up as HTML, which changes how it reads, not whether it is raised. */
    case HtmlErrors = 'html_errors';

    /** Whether a raised error is logged, which writes outside the test and changes nothing it reads. */
    case LogErrors = 'log_errors';

    /** Where a logged error is written, a path that differs from one machine to the next. */
    case ErrorLog = 'error_log';

    /** The file mode of the error log, which only the log's readers see. */
    case ErrorLogMode = 'error_log_mode';

    /** The manual's address a printed error links to, which is only read by a person. */
    case DocrefRoot = 'docref_root';

    /** The extension of the manual's pages a printed error links to, which is only read by a person. */
    case DocrefExt = 'docref_ext';

    /** The pager of PHP's interactive shell, which a runner never starts. */
    case CliPager = 'cli.pager';

    /** The prompt of PHP's interactive shell, which a runner never starts. */
    case CliPrompt = 'cli.prompt';
}
