<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

/** A `reports` entry (ADR-0009): a reporter, and where it writes its file. */
final readonly class Report
{
    private function __construct(private Json $json)
    {
    }

    public static function json(string $path): self
    {
        return self::writing('json', $path);
    }

    public static function junit(string $path): self
    {
        return self::writing('junit', $path);
    }

    public static function sarif(string $path): self
    {
        return self::writing('sarif', $path);
    }

    /** The HTML report, written into a directory. */
    public static function html(string $path): self
    {
        return self::writing('html', $path);
    }

    /** A reporter another extension registers by name, or a class, with its options, writing no file. */
    public static function uses(string $reporter, Option ...$options): self
    {
        return self::with(Json::object(Member::of('use', $reporter)), ...$options);
    }

    /** A reporter another extension registers by name, or a class, with its options, writing its file at a path. */
    public static function writing(string $reporter, string $path, Option ...$options): self
    {
        return self::with(Json::object(Member::of('use', $reporter), Member::of('path', $path)), ...$options);
    }

    /** This entry, as JSON. */
    public function written(): Json
    {
        return $this->json;
    }

    private static function with(Json $report, Option ...$options): self
    {
        return new self($options === [] ? $report : $report->with(Member::of('with', Option::object(...$options))));
    }
}
