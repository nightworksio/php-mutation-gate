<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;

/** A `reports` entry (ADR-0009): a reporter, and where it writes its file. */
final readonly class Report
{
    private function __construct(private Json $json)
    {
    }

    public static function json(string $path): self
    {
        return self::uses('json', $path);
    }

    public static function junit(string $path): self
    {
        return self::uses('junit', $path);
    }

    public static function sarif(string $path): self
    {
        return self::uses('sarif', $path);
    }

    /** The HTML report, written into a directory. */
    public static function html(string $path): self
    {
        return self::uses('html', $path);
    }

    /** A reporter another extension registers by name, or a class, with its options; `''` for no file. */
    public static function uses(string $reporter, string $path = '', Option ...$options): self
    {
        $report = Json::object()->with('use', $reporter);
        $report = $path === '' ? $report : $report->with('path', $path);

        return new self($options === [] ? $report : $report->with('with', Option::object(...$options)));
    }

    /** This entry, as JSON. */
    public function written(): Json
    {
        return $this->json;
    }
}
