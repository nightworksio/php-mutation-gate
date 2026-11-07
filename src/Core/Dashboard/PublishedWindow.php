<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Dashboard;

use NightWorksIO\MutationGate\Core\Cost\SavingsWindow;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;

/**
 * The savings window a repository's `summary.json` names, such as `30d`
 * (ADR-0024): what the dashboard says its savings were counted over.
 *
 * @internal
 */
final readonly class PublishedWindow
{
    /** @throws NotInShape */
    public static function read(Node $window): SavingsWindow
    {
        $read = SavingsWindow::parse($window->text());

        return $read instanceof SavingsWindow ? $read : throw NotInShape::at($window->at(), 'a duration');
    }
}
