<?php
namespace Wpcb\Support;

use Wpcb\Admin\Settings;

/**
 * Canonical time handling for the booking domain.
 *
 * Storage values are always UTC in MySQL DATETIME format.
 * Availability rules are interpreted as wall-clock times in the configured
 * IANA booking timezone.
 */
final class Time {
    public const STORAGE_FORMAT = 'Y-m-d H:i:s';

    public static function utc(): \DateTimeZone {
        static $utc;
        return $utc ?: ($utc = new \DateTimeZone('UTC'));
    }

    public static function bookingTimezoneName(): string {
        $settings = Settings::get();
        $candidate = trim((string)($settings['timezone'] ?? ''));
        if ($candidate !== '') {
            try {
                new \DateTimeZone($candidate);
                return $candidate;
            } catch (\Exception $e) {
                // Fall through to the WordPress site timezone.
            }
        }

        $site = wp_timezone_string();
        if ($site !== '') {
            try {
                new \DateTimeZone($site);
                return $site;
            } catch (\Exception $e) {
                // Fall through to UTC.
            }
        }
        return 'UTC';
    }

    public static function bookingTimezone(): \DateTimeZone {
        return new \DateTimeZone(self::bookingTimezoneName());
    }

    public static function nowUtc(): \DateTimeImmutable {
        return new \DateTimeImmutable('now', self::utc());
    }

    public static function nowLocal(): \DateTimeImmutable {
        return self::nowUtc()->setTimezone(self::bookingTimezone());
    }

    public static function formatUtc(\DateTimeInterface $value): string {
        return (new \DateTimeImmutable('@' . $value->getTimestamp()))
            ->setTimezone(self::utc())
            ->format(self::STORAGE_FORMAT);
    }

    public static function parseUtc(string $value): ?\DateTimeImmutable {
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!' . self::STORAGE_FORMAT, $value, self::utc());
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) {
            return null;
        }
        return $date;
    }

    /**
     * Parse a wall-clock value in the booking timezone.
     *
     * Non-existent spring-forward times and ambiguous fall-back wall times are
     * rejected rather than silently choosing a different instant.
     */
    public static function parseLocal(string $value): ?\DateTimeImmutable {
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
            return null;
        }

        $timezone = self::bookingTimezone();
        $date = \DateTimeImmutable::createFromFormat('!' . self::STORAGE_FORMAT, $value, $timezone);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) {
            return null;
        }

        // PHP normalizes non-existent local times across DST gaps. Reject that.
        if ($date->format(self::STORAGE_FORMAT) !== $value) {
            return null;
        }

        if (self::isAmbiguousLocal($value, $date, $timezone)) {
            return null;
        }

        return $date;
    }

    public static function localToUtc(string $value): ?string {
        $date = self::parseLocal($value);
        return $date ? self::formatUtc($date) : null;
    }

    public static function utcToLocal(string $value): ?string {
        $date = self::parseUtc($value);
        return $date ? $date->setTimezone(self::bookingTimezone())->format(self::STORAGE_FORMAT) : null;
    }

    public static function display(string $utcValue, string $format): string {
        $date = self::parseUtc($utcValue);
        return $date ? $date->setTimezone(self::bookingTimezone())->format($format) : '';
    }

    public static function addMinutes(string $utcValue, int $minutes): ?string {
        $date = self::parseUtc($utcValue);
        if (!$date) {
            return null;
        }
        $sign = $minutes >= 0 ? '+' : '';
        return self::formatUtc($date->modify($sign . $minutes . ' minutes'));
    }

    public static function localDate(string $utcValue): string {
        $date = self::parseUtc($utcValue);
        return $date ? $date->setTimezone(self::bookingTimezone())->format('Y-m-d') : '';
    }

    private static function isAmbiguousLocal(
        string $value,
        \DateTimeImmutable $candidate,
        \DateTimeZone $timezone
    ): bool {
        [$datePart, $timePart] = explode(' ', $value, 2);
        [$year, $month, $day] = array_map('intval', explode('-', $datePart));
        [$hour, $minute, $second] = array_map('intval', explode(':', $timePart));
        $wallEpoch = gmmktime($hour, $minute, $second, $month, $day, $year);

        $transitions = $timezone->getTransitions(
            $candidate->getTimestamp() - 10800,
            $candidate->getTimestamp() + 10800
        );
        if (!is_array($transitions) || count($transitions) < 2) {
            return false;
        }

        $previousOffset = (int)$transitions[0]['offset'];
        foreach (array_slice($transitions, 1) as $transition) {
            $newOffset = (int)$transition['offset'];
            if ($newOffset < $previousOffset) {
                $transitionUtc = (int)$transition['ts'];
                $repeatStartWall = $transitionUtc + $newOffset;
                $repeatEndWall = $transitionUtc + $previousOffset;
                if ($wallEpoch >= $repeatStartWall && $wallEpoch < $repeatEndWall) {
                    return true;
                }
            }
            $previousOffset = $newOffset;
        }
        return false;
    }
}
