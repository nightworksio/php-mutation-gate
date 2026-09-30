<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

/** How a rule is shown to refuse its own violation. */
enum Proof: string
{
    /** A file the analyser reads, planted at the path the rule looks at; the marker is in what it reports for that file. */
    case Analyser = 'analyser';

    /** A file the Arch suite reads; the test the marker names fails and names the file. */
    case Suite = 'suite';

    /** A file the dependency analyser reads; its report names the file. */
    case Dependencies = 'dependencies';

    /** A change to a file the repository owns, made in the copy; the test the marker names fails. */
    case Edit = 'edit';

    /** A change to the lock, made in the copy; `composer audit --locked` over the copy names the advisory. CI only. */
    case Audit = 'audit';

    /**
     * A file of its own, planted in a copy of its own that holds nothing else
     * planted; the covered suite run there falls below its floor and lists the
     * file under 100%. CI only.
     */
    case Coverage = 'coverage';

    /** The rule's own judgement, called with the violation by a test beside it. */
    case Direct = 'direct';

    /** Nothing a snippet can break; the reason is recorded instead. */
    case NotDrivable = 'not-drivable';

    /** Whether this fixture is a whole file of its own, planted beside the real ones. */
    public function isAFileOfItsOwn(): bool
    {
        return match ($this) {
            self::Analyser, self::Suite, self::Dependencies => true,
            self::Edit, self::Audit, self::Coverage, self::Direct, self::NotDrivable => false,
        };
    }

    /** Whether the Arch suite is what refuses this fixture. */
    public function readBySuite(): bool
    {
        return match ($this) {
            self::Suite, self::Edit => true,
            self::Analyser, self::Dependencies, self::Audit, self::Coverage, self::Direct, self::NotDrivable => false,
        };
    }

}
