<?php
namespace Wpcb\Calendar;

interface CalendarProviderInterface {
    public function id(): string;

    public function label(): string;

    /** @return string[] */
    public function capabilities(): array;
}
