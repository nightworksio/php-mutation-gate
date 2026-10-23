<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpSpec;

use function file_put_contents;
use function json_encode;

use NightWorksIO\MutationGate\Core\Format\JsonText;
use PhpSpec\Event\ExampleEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Lists every example of the suite, by its id, and the holding groups each
 * is in, and writes the list once the suite is done, as JSON: `examples`, each
 * id in the order PhpSpec loads them, and `groups`, each group's ids by its
 * name. The run selects no example, so none runs.
 */
final class Listing implements EventSubscriberInterface
{
    /** The member of the list that holds every example. */
    public const string EXAMPLES = 'examples';

    /** The member of the list that holds each group's examples. */
    public const string GROUPS = 'groups';

    /** @var list<string> */
    private array $examples = [];

    /** @var array<string, list<string>> */
    private array $groups = [];

    public function __construct(private readonly string $file)
    {
    }

    /** @return array<string, string> */
    public static function getSubscribedEvents(): array
    {
        return ['beforeExample' => 'listed', 'afterSuite' => 'written'];
    }

    public function listed(ExampleEvent $event): void
    {
        $example = Example::of($event->getExample());
        $this->examples[] = $example->id();

        foreach ($example->groups() as $group) {
            $this->groups[$group->name()][] = $example->id();
        }
    }

    public function written(): void
    {
        file_put_contents(
            $this->file,
            json_encode([self::EXAMPLES => $this->examples, self::GROUPS => $this->groups], JsonText::FLAGS),
        );
    }
}
