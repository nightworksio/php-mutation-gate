<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function is_string;

use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Configurable;
use NightWorksIO\MutationGate\Port\Reporter;

/** A reporter a config names by its class, which needs a `channel` among its options. */
final readonly class ConfigurableReporter implements Configurable, Reporter
{
    private function __construct(public string $channel)
    {
    }

    public static function fromOptions(Options $options): self|Invalid
    {
        $channel = $options->text(Key::of('channel'));

        return match (true) {
            $channel instanceof Problem => Invalid::because($channel),
            ! is_string($channel) || $channel === '' => Invalid::because(
                Problem::at('channel', 'expected a channel name, got nothing'),
                Problem::at('', 'needs a channel'),
            ),
            default => new self($channel),
        };
    }

    public function report(Verdict $verdict): Written
    {
        return Written::to($this->channel);
    }
}
