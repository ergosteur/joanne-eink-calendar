<?php
// web/lib/ical.php — iCalendar parsing.
//
// Scope: the subset of RFC 5545 that real calendar exports actually use for display.
// Recurrence covers FREQ/INTERVAL/COUNT/UNTIL/BYDAY/BYMONTHDAY/BYMONTH, plus EXDATE
// exclusions and RECURRENCE-ID overrides. VTIMEZONE definitions are not interpreted;
// TZID values are resolved against the system database with a fallback instead.
//
// Expansion is always bounded by the caller's window and by hard iteration caps, so a
// malformed or unbounded rule cannot hang a request.

class LibreIcal
{
    /** Hard stops so a pathological rule cannot spin. */
    private const MAX_OCCURRENCES = 1000;
    private const MAX_PERIODS = 20000;

    private const WEEKDAYS = ['SU' => 0, 'MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6];

    /**
     * Exchange and Outlook emit Windows zone names in TZID, which are not IANA
     * identifiers. Passing one to DateTimeZone throws, which previously took down the
     * whole request. These are the ones that show up in practice.
     */
    private const WINDOWS_TZ = [
        'AUS Eastern Standard Time'   => 'Australia/Sydney',
        'Atlantic Standard Time'      => 'America/Halifax',
        'Canada Central Standard Time'=> 'America/Regina',
        'Cen. Australia Standard Time'=> 'Australia/Adelaide',
        'Central America Standard Time' => 'America/Guatemala',
        'Central Europe Standard Time'=> 'Europe/Budapest',
        'Central European Standard Time' => 'Europe/Warsaw',
        'Central Standard Time'       => 'America/Chicago',
        'China Standard Time'         => 'Asia/Shanghai',
        'E. Australia Standard Time'  => 'Australia/Brisbane',
        'Eastern Standard Time'       => 'America/New_York',
        'FLE Standard Time'           => 'Europe/Kyiv',
        'GMT Standard Time'           => 'Europe/London',
        'Greenwich Standard Time'     => 'Atlantic/Reykjavik',
        'India Standard Time'         => 'Asia/Kolkata',
        'Mountain Standard Time'      => 'America/Denver',
        'New Zealand Standard Time'   => 'Pacific/Auckland',
        'Pacific SA Standard Time'    => 'America/Santiago',
        'Pacific Standard Time'       => 'America/Los_Angeles',
        'Romance Standard Time'       => 'Europe/Paris',
        'SA Pacific Standard Time'    => 'America/Bogota',
        'Singapore Standard Time'     => 'Asia/Singapore',
        'South Africa Standard Time'  => 'Africa/Johannesburg',
        'Tokyo Standard Time'         => 'Asia/Tokyo',
        'US Eastern Standard Time'    => 'America/Indianapolis',
        'US Mountain Standard Time'   => 'America/Phoenix',
        'W. Central Africa Standard Time' => 'Africa/Lagos',
        'W. Europe Standard Time'     => 'Europe/Berlin',
    ];

    /** @var array<string, DateTimeZone> */
    private static array $tzCache = [];

    public static function unescapeText(string $text): string
    {
        return str_replace(
            ['\\,', '\\;', '\\\\', '\\n', '\\N'],
            [',', ';', '\\', "\n", "\n"],
            $text
        );
    }

    /**
     * Resolve a TZID to a usable zone, never throwing.
     */
    public static function timezone(string $name, DateTimeZone $fallback): DateTimeZone
    {
        $name = trim($name, " \t\"");
        if ($name === '') {
            return $fallback;
        }

        $cacheKey = $name . '|' . $fallback->getName();
        if (isset(self::$tzCache[$cacheKey])) {
            return self::$tzCache[$cacheKey];
        }

        $candidates = [];
        if (isset(self::WINDOWS_TZ[$name])) {
            $candidates[] = self::WINDOWS_TZ[$name];
        }
        $candidates[] = $name;
        // Some exporters prefix the identifier, e.g. /mozilla.org/20050126_1/Europe/Paris
        if (str_contains($name, '/')) {
            $parts = explode('/', trim($name, '/'));
            if (count($parts) >= 2) {
                $candidates[] = implode('/', array_slice($parts, -2));
            }
        }

        foreach ($candidates as $candidate) {
            try {
                return self::$tzCache[$cacheKey] = new DateTimeZone($candidate);
            } catch (Throwable $e) {
                // Try the next candidate.
            }
        }

        return self::$tzCache[$cacheKey] = $fallback;
    }

    /**
     * Parse a calendar and return display-ready events overlapping the window.
     *
     * @return list<array{start: DateTimeImmutable, end: DateTimeImmutable, summary: string, is_allday: bool}>
     */
    public static function parseEvents(
        string $ics,
        DateTimeZone $defaultTz,
        DateTimeZone $displayTz,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd
    ): array {
        // Normalise line endings, then unfold continuation lines.
        $ics = str_replace(["\r\n", "\r"], "\n", $ics);
        $ics = (string)preg_replace('/\n[ \t]/', '', $ics);

        if (!preg_match_all('/BEGIN:VEVENT\n(.*?)\nEND:VEVENT/s', $ics, $matches)) {
            return [];
        }

        $parsedBlocks = [];
        // uid => [recurrence-id timestamp => true]; a modified instance replaces the
        // generated one rather than appearing twice.
        $overrides = [];

        foreach ($matches[1] as $block) {
            $event = self::parseVevent($block, $defaultTz);
            if ($event === null) {
                continue;
            }
            if ($event['recurrence_id'] !== null) {
                $overrides[$event['uid']][$event['recurrence_id']] = true;
            }
            $parsedBlocks[] = $event;
        }

        $events = [];
        foreach ($parsedBlocks as $event) {
            $exclusions = $event['exdates'];
            if ($event['rrule'] !== '' && $event['recurrence_id'] === null) {
                foreach (($overrides[$event['uid']] ?? []) as $ts => $_) {
                    $exclusions[$ts] = true;
                }
                $occurrences = self::expand($event, $exclusions, $windowStart, $windowEnd);
            } else {
                $occurrences = self::overlaps($event['start'], $event['end'], $windowStart, $windowEnd)
                    ? [[$event['start'], $event['end']]]
                    : [];
            }

            foreach ($occurrences as [$start, $end]) {
                // All-day values are wall-clock dates and must not be shifted; timed
                // values are absolute instants and are converted for display.
                if (!$event['is_allday']) {
                    $start = $start->setTimezone($displayTz);
                    $end = $end->setTimezone($displayTz);
                }
                $events[] = [
                    'start'     => $start,
                    'end'       => $end,
                    'summary'   => $event['summary'],
                    'is_allday' => $event['is_allday'],
                ];
            }
        }

        return $events;
    }

    // ----------------------------------------------------------------- internals

    /**
     * @return list<array{name: string, params: array<string,string>, value: string}>
     */
    private static function properties(string $block): array
    {
        $out = [];
        foreach (explode("\n", $block) as $line) {
            if ($line === '') {
                continue;
            }
            if (!preg_match('/^([A-Za-z0-9-]+)((?:;[^:]*)?):(.*)$/s', $line, $m)) {
                continue;
            }
            $params = [];
            if ($m[2] !== '') {
                foreach (explode(';', ltrim($m[2], ';')) as $pair) {
                    if ($pair === '') {
                        continue;
                    }
                    $kv = explode('=', $pair, 2);
                    $params[strtoupper($kv[0])] = isset($kv[1]) ? trim($kv[1], '"') : '';
                }
            }
            $out[] = ['name' => strtoupper($m[1]), 'params' => $params, 'value' => $m[3]];
        }
        return $out;
    }

    private static function parseVevent(string $block, DateTimeZone $defaultTz): ?array
    {
        $props = self::properties($block);

        $get = static function (string $name) use ($props): ?array {
            foreach ($props as $p) {
                if ($p['name'] === $name) {
                    return $p;
                }
            }
            return null;
        };

        $dtstart = $get('DTSTART');
        $summary = $get('SUMMARY');
        if ($dtstart === null || $summary === null) {
            return null;
        }

        $isAllDay = (($dtstart['params']['VALUE'] ?? '') === 'DATE')
            || strlen(trim($dtstart['value'])) === 8;

        $startTz = self::timezone($dtstart['params']['TZID'] ?? '', $defaultTz);
        $start = self::parseDate($dtstart['value'], $startTz, $isAllDay);
        if ($start === null) {
            // A malformed DTSTART previously produced a false that survived an isset()
            // check and fatally errored on the next method call.
            return null;
        }

        $end = null;
        $dtend = $get('DTEND');
        if ($dtend !== null) {
            $endTz = self::timezone($dtend['params']['TZID'] ?? '', $defaultTz);
            $end = self::parseDate($dtend['value'], $endTz, $isAllDay);
        }
        if ($end === null) {
            $duration = $get('DURATION');
            if ($duration !== null) {
                $end = self::applyDuration($start, trim($duration['value']));
            }
        }
        if ($end === null) {
            // RFC 5545 3.6.1: absent DTEND and DURATION means one day for a DATE value,
            // and a zero-length instant otherwise.
            $end = $isAllDay ? $start->modify('+1 day') : $start;
        }
        if ($end < $start) {
            $end = $start;
        }

        $recurrenceId = null;
        $recurrence = $get('RECURRENCE-ID');
        if ($recurrence !== null) {
            $recTz = self::timezone($recurrence['params']['TZID'] ?? '', $defaultTz);
            $parsed = self::parseDate($recurrence['value'], $recTz, $isAllDay);
            if ($parsed !== null) {
                $recurrenceId = $parsed->getTimestamp();
            }
        }

        $exdates = [];
        foreach ($props as $p) {
            if ($p['name'] !== 'EXDATE') {
                continue;
            }
            $exTz = self::timezone($p['params']['TZID'] ?? '', $defaultTz);
            foreach (explode(',', $p['value']) as $value) {
                $parsed = self::parseDate($value, $exTz, $isAllDay);
                if ($parsed !== null) {
                    $exdates[$parsed->getTimestamp()] = true;
                }
            }
        }

        $rrule = $get('RRULE');
        $uid = $get('UID');

        return [
            'uid'           => $uid !== null ? trim($uid['value']) : spl_object_hash((object)$block),
            'summary'       => self::unescapeText(trim($summary['value'])),
            'start'         => $start,
            'end'           => $end,
            'is_allday'     => $isAllDay,
            'rrule'         => $rrule !== null ? trim($rrule['value']) : '',
            'exdates'       => $exdates,
            'recurrence_id' => $recurrenceId,
        ];
    }

    private static function parseDate(string $value, DateTimeZone $tz, bool $isAllDay): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if ($isAllDay || strlen($value) === 8) {
            $parsed = DateTimeImmutable::createFromFormat('!Ymd', $value, $tz);
        } elseif (str_ends_with($value, 'Z')) {
            $parsed = DateTimeImmutable::createFromFormat('Ymd\THis\Z', $value, new DateTimeZone('UTC'));
        } else {
            $parsed = DateTimeImmutable::createFromFormat('Ymd\THis', $value, $tz);
        }

        return $parsed instanceof DateTimeImmutable ? $parsed : null;
    }

    private static function applyDuration(DateTimeImmutable $start, string $duration): ?DateTimeImmutable
    {
        $negative = str_starts_with($duration, '-');
        $duration = ltrim($duration, '+-');
        try {
            $interval = new DateInterval($duration);
        } catch (Throwable $e) {
            return null;
        }
        return $negative ? $start->sub($interval) : $start->add($interval);
    }

    private static function overlaps(
        DateTimeInterface $start,
        DateTimeInterface $end,
        DateTimeInterface $windowStart,
        DateTimeInterface $windowEnd
    ): bool {
        return $end >= $windowStart && $start <= $windowEnd;
    }

    /**
     * @return list<array{0: DateTimeImmutable, 1: DateTimeImmutable}>
     */
    private static function expand(
        array $event,
        array $exclusions,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd
    ): array {
        $rule = self::parseRrule($event['rrule']);
        $freq = $rule['FREQ'] ?? '';
        if (!in_array($freq, ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'], true)) {
            // An unsupported frequency still has a first occurrence.
            return self::overlaps($event['start'], $event['end'], $windowStart, $windowEnd)
                ? [[$event['start'], $event['end']]]
                : [];
        }

        $interval = max(1, (int)($rule['INTERVAL'] ?? 1));
        $count = isset($rule['COUNT']) ? max(0, (int)$rule['COUNT']) : null;
        $until = null;
        if (isset($rule['UNTIL'])) {
            $until = self::parseDate($rule['UNTIL'], new DateTimeZone('UTC'), strlen(trim($rule['UNTIL'])) === 8);
        }

        $byDay = isset($rule['BYDAY']) ? array_filter(explode(',', $rule['BYDAY'])) : [];
        $byMonthDay = isset($rule['BYMONTHDAY'])
            ? array_map('intval', array_filter(explode(',', $rule['BYMONTHDAY'])))
            : [];
        $byMonth = isset($rule['BYMONTH'])
            ? array_map('intval', array_filter(explode(',', $rule['BYMONTH'])))
            : [];

        $start = $event['start'];
        $isAllDay = $event['is_allday'];
        $durationSeconds = $event['end']->getTimestamp() - $start->getTimestamp();
        $durationDays = $isAllDay ? max(1, (int)round($durationSeconds / 86400)) : 0;

        $cursor = $start;

        // COUNT is defined over every occurrence from the start, so it has to be walked.
        // Without it, skip whole intervals to reach the window instead.
        if ($count === null) {
            $cursor = self::fastForward($cursor, $freq, $interval, $windowStart->modify("-{$durationSeconds} seconds"));
        }

        $out = [];
        $emitted = 0;
        $periods = 0;

        while ($periods++ < self::MAX_PERIODS) {
            if (count($out) >= self::MAX_OCCURRENCES) {
                break;
            }

            $candidates = self::candidates($cursor, $start, $freq, $byDay, $byMonthDay, $byMonth);

            foreach ($candidates as $candidate) {
                if ($candidate < $start) {
                    continue;
                }
                if ($until !== null && $candidate > $until) {
                    break 2;
                }
                if ($count !== null && $emitted >= $count) {
                    break 2;
                }
                $emitted++;

                if (isset($exclusions[$candidate->getTimestamp()])) {
                    continue;
                }

                $end = $isAllDay
                    ? $candidate->modify("+{$durationDays} days")
                    : $candidate->modify("+{$durationSeconds} seconds");

                if (self::overlaps($candidate, $end, $windowStart, $windowEnd)) {
                    $out[] = [$candidate, $end];
                }
            }

            // Every candidate in this period is already past the window.
            if ($candidates !== [] && min($candidates) > $windowEnd) {
                break;
            }

            $cursor = self::advance($cursor, $freq, $interval);
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private static function parseRrule(string $rrule): array
    {
        $out = [];
        foreach (explode(';', $rrule) as $part) {
            if ($part === '' || !str_contains($part, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $part, 2);
            $out[strtoupper(trim($k))] = strtoupper(trim($v));
        }
        return $out;
    }

    private static function advance(DateTimeImmutable $cursor, string $freq, int $interval): DateTimeImmutable
    {
        switch ($freq) {
            case 'DAILY':
                return $cursor->modify("+{$interval} days");
            case 'WEEKLY':
                return $cursor->modify('+' . ($interval * 7) . ' days');
            case 'MONTHLY':
                // Anchor to the first of the month so a 31st start does not roll into
                // the following month on shorter months.
                return $cursor->modify('first day of this month')->modify("+{$interval} months");
            default:
                return $cursor->modify("+{$interval} years");
        }
    }

    /**
     * Skip whole intervals to bring the cursor up to the window without walking.
     */
    private static function fastForward(
        DateTimeImmutable $cursor,
        string $freq,
        int $interval,
        DateTimeImmutable $target
    ): DateTimeImmutable {
        if ($cursor >= $target) {
            return $cursor;
        }

        $seconds = $target->getTimestamp() - $cursor->getTimestamp();

        switch ($freq) {
            case 'DAILY':
                $steps = intdiv(intdiv($seconds, 86400), $interval);
                return $steps > 0 ? $cursor->modify('+' . ($steps * $interval) . ' days') : $cursor;
            case 'WEEKLY':
                $steps = intdiv(intdiv($seconds, 604800), $interval);
                return $steps > 0 ? $cursor->modify('+' . ($steps * $interval * 7) . ' days') : $cursor;
            case 'MONTHLY':
                $months = ((int)$target->format('Y') - (int)$cursor->format('Y')) * 12
                    + ((int)$target->format('n') - (int)$cursor->format('n'));
                $steps = intdiv(max(0, $months), $interval);
                return $steps > 0
                    ? $cursor->modify('first day of this month')->modify('+' . ($steps * $interval) . ' months')
                    : $cursor;
            default:
                $years = (int)$target->format('Y') - (int)$cursor->format('Y');
                $steps = intdiv(max(0, $years), $interval);
                return $steps > 0 ? $cursor->modify('+' . ($steps * $interval) . ' years') : $cursor;
        }
    }

    /**
     * Occurrence start times within the period the cursor sits in.
     *
     * @return list<DateTimeImmutable>
     */
    private static function candidates(
        DateTimeImmutable $cursor,
        DateTimeImmutable $start,
        string $freq,
        array $byDay,
        array $byMonthDay,
        array $byMonth
    ): array {
        $time = $start->format('H:i:s');

        if ($freq === 'DAILY') {
            // BYDAY acts as a filter for daily rules, e.g. every weekday.
            if ($byDay !== [] && !self::matchesWeekday($cursor, $byDay)) {
                return [];
            }
            return [$cursor];
        }

        if ($freq === 'WEEKLY') {
            if ($byDay === []) {
                return [$cursor];
            }
            // Walk the seven days of the cursor's week, starting Monday.
            $weekStart = $cursor->modify('monday this week')->setTime(
                (int)$start->format('G'),
                (int)$start->format('i'),
                (int)$start->format('s')
            );
            $out = [];
            for ($i = 0; $i < 7; $i++) {
                $day = $weekStart->modify("+{$i} days");
                if (self::matchesWeekday($day, $byDay)) {
                    $out[] = $day;
                }
            }
            return $out;
        }

        if ($freq === 'MONTHLY') {
            $monthStart = $cursor->modify('first day of this month');
            $out = [];

            if ($byDay !== []) {
                $out = self::byDayInMonth($monthStart, $byDay, $time);
            } elseif ($byMonthDay !== []) {
                foreach ($byMonthDay as $day) {
                    $resolved = self::dayOfMonth($monthStart, $day, $time);
                    if ($resolved !== null) {
                        $out[] = $resolved;
                    }
                }
            } else {
                $resolved = self::dayOfMonth($monthStart, (int)$start->format('j'), $time);
                if ($resolved !== null) {
                    $out[] = $resolved;
                }
            }

            sort($out);
            return $out;
        }

        // YEARLY
        $months = $byMonth !== [] ? $byMonth : [(int)$start->format('n')];
        $out = [];
        foreach ($months as $month) {
            $monthStart = $cursor->setDate((int)$cursor->format('Y'), $month, 1);
            if ($byDay !== []) {
                foreach (self::byDayInMonth($monthStart, $byDay, $time) as $candidate) {
                    $out[] = $candidate;
                }
            } else {
                $day = $byMonthDay !== [] ? $byMonthDay[0] : (int)$start->format('j');
                $resolved = self::dayOfMonth($monthStart, $day, $time);
                if ($resolved !== null) {
                    $out[] = $resolved;
                }
            }
        }
        sort($out);
        return $out;
    }

    private static function matchesWeekday(DateTimeImmutable $date, array $byDay): bool
    {
        $dow = (int)$date->format('w');
        foreach ($byDay as $entry) {
            $code = substr(trim($entry), -2);
            if (isset(self::WEEKDAYS[$code]) && self::WEEKDAYS[$code] === $dow) {
                return true;
            }
        }
        return false;
    }

    /**
     * Resolve BYDAY entries, with or without an ordinal prefix, inside one month.
     *
     * @return list<DateTimeImmutable>
     */
    private static function byDayInMonth(DateTimeImmutable $monthStart, array $byDay, string $time): array
    {
        [$h, $i, $s] = array_map('intval', explode(':', $time));
        $daysInMonth = (int)$monthStart->format('t');
        $out = [];

        foreach ($byDay as $entry) {
            $entry = trim($entry);
            $code = substr($entry, -2);
            if (!isset(self::WEEKDAYS[$code])) {
                continue;
            }
            $ordinal = (int)substr($entry, 0, -2); // 0 when absent
            $target = self::WEEKDAYS[$code];

            $matches = [];
            for ($day = 1; $day <= $daysInMonth; $day++) {
                $date = $monthStart->setDate((int)$monthStart->format('Y'), (int)$monthStart->format('n'), $day);
                if ((int)$date->format('w') === $target) {
                    $matches[] = $date->setTime($h, $i, $s);
                }
            }

            if ($ordinal === 0) {
                foreach ($matches as $match) {
                    $out[] = $match;
                }
            } elseif ($ordinal > 0 && isset($matches[$ordinal - 1])) {
                $out[] = $matches[$ordinal - 1];
            } elseif ($ordinal < 0 && isset($matches[count($matches) + $ordinal])) {
                $out[] = $matches[count($matches) + $ordinal];
            }
        }

        return $out;
    }

    private static function dayOfMonth(DateTimeImmutable $monthStart, int $day, string $time): ?DateTimeImmutable
    {
        $daysInMonth = (int)$monthStart->format('t');
        if ($day < 0) {
            $day = $daysInMonth + $day + 1;
        }
        // A rule for the 31st simply does not occur in shorter months.
        if ($day < 1 || $day > $daysInMonth) {
            return null;
        }
        [$h, $i, $s] = array_map('intval', explode(':', $time));
        return $monthStart
            ->setDate((int)$monthStart->format('Y'), (int)$monthStart->format('n'), $day)
            ->setTime($h, $i, $s);
    }
}
