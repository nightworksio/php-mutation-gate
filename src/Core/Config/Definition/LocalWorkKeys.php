<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use function array_map;
use function array_values;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\LocalWork;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Local\CpuProfile;
use NightWorksIO\MutationGate\Core\Local\MacosClass;
use NightWorksIO\MutationGate\Core\Local\OnBattery;
use NightWorksIO\MutationGate\Core\Local\Priority;
use NightWorksIO\MutationGate\Core\Local\WindowsClass;
use NightWorksIO\MutationGate\Core\Local\WorkerShare;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * How the keys of `local` that say how `watch` and `serve` use the machine
 * are read into its `LocalWork` (ADR-0002, ADR-0120).
 *
 * @internal
 */
final readonly class LocalWorkKeys
{
    private const Effect EFFECT = Effect::JudgesOrReportsOnly;

    /** @return list<Field<object|scalar>> */
    public static function fields(): array
    {
        return [
            self::cpu(),
            self::workers(),
            self::priority(),
            self::macosClass(),
            self::linuxNice(),
            self::windowsClass(),
            self::onBattery(),
            self::childWait(),
            ...self::switches(),
        ];
    }

    /**
     * What the keys of this `local` section hold, or every problem with them, as a reading.
     *
     * @return Reading<LocalWork>
     */
    public static function read(Node $local): Reading
    {
        $cpu = self::cpu()->read($local);
        $workers = self::workers()->read($local);
        $priority = self::priority()->read($local);
        $macos = self::macosClass()->read($local);
        $nice = self::linuxNice()->read($local);
        $windows = self::windowsClass()->read($local);
        $battery = self::onBattery()->read($local);
        $wait = self::childWait()->read($local);
        $switches = [];

        foreach (self::switches() as $field) {
            $switches[$field->key()] = $field->read($local);
        }

        $work = Reading::built(
            static fn(): LocalWork => LocalWork::of(
                $cpu->value(),
                $workers->value(),
                $priority->value(),
                $macos->value(),
                $nice->value(),
                $windows->value(),
                $battery->value(),
                $wait->value(),
                array_map(static fn(Reading $read): bool|Absent => $read->value(), $switches),
            ),
            $cpu,
            $workers,
            $priority,
            $macos,
            $nice,
            $windows,
            $battery,
            $wait,
            ...array_values($switches),
        );

        return $work instanceof Invalid ? Reading::invalid($work) : Reading::of($work);
    }

    /** @return Field<CpuProfile> */
    private static function cpu(): Field
    {
        return Field::optional('cpu', Enumerated::of(CpuProfile::cases()), self::EFFECT);
    }

    /** @return Field<int|WorkerShare> */
    private static function workers(): Field
    {
        return Field::optional('workers', new WorkerCount(), self::EFFECT);
    }

    /** @return Field<Priority> */
    private static function priority(): Field
    {
        return Field::optional('priority', Enumerated::of(Priority::cases()), self::EFFECT);
    }

    /** @return Field<MacosClass> */
    private static function macosClass(): Field
    {
        return Field::optional('macosClass', Enumerated::of(MacosClass::cases()), self::EFFECT);
    }

    /** @return Field<int> */
    private static function linuxNice(): Field
    {
        $nice = Integer::between(Priority::Normal->nice(), Priority::Background->nice());

        return Field::optional('linuxNice', $nice, self::EFFECT);
    }

    /** @return Field<WindowsClass> */
    private static function windowsClass(): Field
    {
        return Field::optional('windowsClass', Enumerated::of(WindowsClass::cases()), self::EFFECT);
    }

    /** @return Field<OnBattery> */
    private static function onBattery(): Field
    {
        return Field::optional('onBattery', Enumerated::of(OnBattery::cases()), self::EFFECT);
    }

    /** @return Field<Seconds> */
    private static function childWait(): Field
    {
        return Field::optional('childWait', Duration::written(), self::EFFECT);
    }

    /** @return list<Field<bool>> */
    private static function switches(): array
    {
        return array_map(
            static fn(string $switch): Field => Field::optional($switch, Flag::boolean(), self::EFFECT),
            LocalWork::SWITCHES,
        );
    }
}
