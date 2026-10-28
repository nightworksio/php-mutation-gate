<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function file_put_contents;
use function mb_strlen;
use function mb_substr;

use NightWorksIO\MutationGate\Core\Runner\PhpUnitOption;

use function sprintf;
use function str_starts_with;

/** A JUnit log a fake run writes where its command's `--log-junit` names, recording one test case failed. */
final class JUnitLogs
{
    private const string FAILED = '<testsuites><testsuite name="T"><testcase name="%s" file="%s" class="%s"><failure>x</failure></testcase></testsuite></testsuites>';

    /** @param list<string> $arguments the command's */
    public static function failing(array $arguments, string $name, string $file, string $class): void
    {
        $option = sprintf('%s=', PhpUnitOption::LogJunit->value);

        foreach ($arguments as $argument) {
            if (str_starts_with($argument, $option)) {
                file_put_contents(mb_substr($argument, mb_strlen($option)), sprintf(self::FAILED, $name, $file, $class));
            }
        }
    }
}
