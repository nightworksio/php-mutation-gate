<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function sprintf;
use function var_export;

/**
 * What a fresh PHP process says it was asked of outbreak.so's functions, each a stand-in that keeps its call.
 */
final class OutbreakCalls
{
    /**
     * The calls, joined by semicolons, as the process runs the program after the stand-ins, with $plan a stream plan
     * whose results go to the file named, and anything the program echoes first.
     */
    public static function asked(string $program, string $results): string
    {
        return FreshProcess::answer(sprintf(<<<'CODE'
            $GLOBALS['asked'] = [];

            function outbreak_steps_share(int $children): bool { $GLOBALS['asked'][] = "share {$children}"; return true; }
            function outbreak_steps_limit(int $slot, int $steps): void { $GLOBALS['asked'][] = "limit {$slot} " . ($steps === PHP_INT_MAX ? 'unlimited' : $steps); }
            function outbreak_steps_bind(int $slot): void { $GLOBALS['asked'][] = "bind {$slot}"; }
            function outbreak_steps(): int { return 0; }
            function outbreak_lines_start(): bool { $GLOBALS['asked'][] = 'lines'; return true; }
            function outbreak_lines_stop(): array { $GLOBALS['asked'][] = 'stopped'; return ['/p/Money.php' => [12, 11]]; }
            function outbreak_state_watch(string $gate): int { $GLOBALS['asked'][] = 'watch'; return 7; }
            function outbreak_touches_start(string $root): void { $GLOBALS['asked'][] = 'touches'; }
            function outbreak_touched(): bool { $GLOBALS['asked'][] = 'touched'; return true; }
            function outbreak_state_digest(int $watch): string { $GLOBALS['asked'][] = "digest {$watch}"; return 'd1'; }

            use NightWorksIO\MutationGate\Adapter\Pest\Split\ChildLimit;
            use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamPlan;

            $plan = new StreamPlan(%s, [], [], [], 2, new ChildLimit(1.0, 1.0, 10.0, stepFloor: 100));
            %s
            echo implode(';', $GLOBALS['asked']);
            CODE, var_export($results, return: true), $program));
    }
}
