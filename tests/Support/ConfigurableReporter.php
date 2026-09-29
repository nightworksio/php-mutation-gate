<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function is_array;
use function is_string;
use function json_decode;

use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Configurable;
use NightWorksIO\MutationGate\Extension\Options;
use NightWorksIO\MutationGate\Port\Reporter;

/** A reporter a config names by its class, which needs a `channel` among its options. */
final readonly class ConfigurableReporter implements Configurable, Reporter
{
    private function __construct(public string $channel)
    {
    }

    public static function fromOptions(Options $options): self|Invalid
    {
        $decoded = json_decode($options->json(), associative: true);
        $channel = is_array($decoded) && is_string($decoded['channel'] ?? null) ? $decoded['channel'] : '';

        return $channel === ''
            ? Invalid::because(
                Problem::at('channel', 'expected a channel name, got nothing'),
                Problem::at('', 'needs a channel'),
            )
            : new self($channel);
    }

    public function report(Verdict $verdict): Written|NotWritten
    {
        return Written::to($this->channel);
    }
}
