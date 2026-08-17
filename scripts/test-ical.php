<?php
// scripts/test-ical.php — assertions for the iCalendar parser.
//
//   scripts/php scripts/test-ical.php
//
// Run by scripts/smoke.sh. Covers the parsing defects that previously took the whole
// display down, plus recurrence expansion, which has no other coverage.

require_once __DIR__ . '/../web/lib/ical.php';

$passed = 0;
$failed = 0;

function check(string $label, $actual, $expected): void
{
    global $passed, $failed;
    if ($actual === $expected) {
        $passed++;
        printf("  PASS %s\n", $label);
        return;
    }
    $failed++;
    printf("  FAIL %s\n       expected: %s\n       actual:   %s\n",
        $label, var_export($expected, true), var_export($actual, true));
}

function calendar(string $body): string
{
    return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\n{$body}\r\nEND:VCALENDAR\r\n";
}

/** @return list<string> "YYYY-MM-DD HH:MM summary" for each occurrence, sorted. */
function parse(string $ics, string $from, string $to, string $tz = 'America/Toronto'): array
{
    $zone = new DateTimeZone($tz);
    $events = LibreIcal::parseEvents(
        $ics,
        $zone,
        $zone,
        new DateTimeImmutable($from, $zone),
        new DateTimeImmutable($to, $zone)
    );
    $out = [];
    foreach ($events as $e) {
        $out[] = $e['start']->format('Y-m-d H:i') . ' ' . $e['summary'];
    }
    sort($out);
    return $out;
}

echo "iCalendar parser\n";

// -- Regression: a malformed DTSTART must not fatal -------------------------------
// createFromFormat() returns false, which survived isset() and fatally errored on the
// next method call, so one bad event killed every feed in the request.
$ics = calendar(
    "BEGIN:VEVENT\r\nUID:bad\r\nDTSTART:NOT-A-DATE\r\nDTEND:20260817T110000Z\r\nSUMMARY:Broken\r\nEND:VEVENT\r\n" .
    "BEGIN:VEVENT\r\nUID:good\r\nDTSTART:20260817T140000Z\r\nDTEND:20260817T150000Z\r\nSUMMARY:Survivor\r\nEND:VEVENT"
);
check('malformed DTSTART is skipped, siblings survive',
    parse($ics, '2026-08-01', '2026-09-01'),
    ['2026-08-17 10:00 Survivor']);

// -- Regression: an unknown TZID must not throw -----------------------------------
$ics = calendar(
    "BEGIN:VEVENT\r\nUID:win\r\nDTSTART;TZID=Eastern Standard Time:20260817T090000\r\n" .
    "DTEND;TZID=Eastern Standard Time:20260817T093000\r\nSUMMARY:Outlook\r\nEND:VEVENT"
);
check('Windows TZID maps to IANA',
    parse($ics, '2026-08-01', '2026-09-01'),
    ['2026-08-17 09:00 Outlook']);

$ics = calendar(
    "BEGIN:VEVENT\r\nUID:junk\r\nDTSTART;TZID=Nonsense/Zone:20260817T090000\r\n" .
    "DTEND;TZID=Nonsense/Zone:20260817T093000\r\nSUMMARY:Fallback\r\nEND:VEVENT"
);
check('unresolvable TZID falls back instead of throwing',
    parse($ics, '2026-08-01', '2026-09-01'),
    ['2026-08-17 09:00 Fallback']);

// -- DTEND absent -----------------------------------------------------------------
$ics = calendar(
    "BEGIN:VEVENT\r\nUID:dur\r\nDTSTART:20260817T140000Z\r\nDURATION:PT90M\r\nSUMMARY:Duration\r\nEND:VEVENT"
);
$zone = new DateTimeZone('America/Toronto');
$events = LibreIcal::parseEvents($ics, $zone, $zone,
    new DateTimeImmutable('2026-08-01', $zone), new DateTimeImmutable('2026-09-01', $zone));
check('DURATION replaces a missing DTEND', count($events), 1);
check('DURATION yields the right end time',
    $events[0]['end']->format('H:i'), '11:30');

$ics = calendar(
    "BEGIN:VEVENT\r\nUID:allday\r\nDTSTART;VALUE=DATE:20260817\r\nSUMMARY:Holiday\r\nEND:VEVENT"
);
check('all-day event with neither DTEND nor DURATION is kept',
    parse($ics, '2026-08-01', '2026-09-01'),
    ['2026-08-17 00:00 Holiday']);

// -- Line folding -----------------------------------------------------------------
$ics = calendar(
    "BEGIN:VEVENT\r\nUID:fold\r\nDTSTART:20260817T140000Z\r\nDTEND:20260817T150000Z\r\n" .
    "SUMMARY:A very long meeting title that the\r\n  exporter folded\r\nEND:VEVENT"
);
check('folded lines are joined',
    parse($ics, '2026-08-01', '2026-09-01'),
    ['2026-08-17 10:00 A very long meeting title that the exporter folded']);

// -- Recurrence: previously not supported at all ----------------------------------
$ics = calendar(
    "BEGIN:VEVENT\r\nUID:daily\r\nDTSTART:20260817T130000Z\r\nDTEND:20260817T133000Z\r\n" .
    "RRULE:FREQ=DAILY;COUNT=4\r\nSUMMARY:Standup\r\nEND:VEVENT"
);
check('FREQ=DAILY with COUNT',
    parse($ics, '2026-08-01', '2026-09-01'),
    ['2026-08-17 09:00 Standup', '2026-08-18 09:00 Standup',
     '2026-08-19 09:00 Standup', '2026-08-20 09:00 Standup']);

$ics = calendar(
    "BEGIN:VEVENT\r\nUID:weekly\r\nDTSTART:20260817T130000Z\r\nDTEND:20260817T133000Z\r\n" .
    "RRULE:FREQ=WEEKLY;BYDAY=MO,WE;UNTIL=20260828T000000Z\r\nSUMMARY:Sync\r\nEND:VEVENT"
);
check('FREQ=WEEKLY with BYDAY and UNTIL',
    parse($ics, '2026-08-01', '2026-09-01'),
    ['2026-08-17 09:00 Sync', '2026-08-19 09:00 Sync',
     '2026-08-24 09:00 Sync', '2026-08-26 09:00 Sync']);

$ics = calendar(
    "BEGIN:VEVENT\r\nUID:monthly\r\nDTSTART:20260805T130000Z\r\nDTEND:20260805T140000Z\r\n" .
    "RRULE:FREQ=MONTHLY;BYDAY=1WE;COUNT=3\r\nSUMMARY:Board\r\nEND:VEVENT"
);
check('FREQ=MONTHLY with an ordinal BYDAY',
    parse($ics, '2026-08-01', '2026-11-01'),
    ['2026-08-05 09:00 Board', '2026-09-02 09:00 Board', '2026-10-07 09:00 Board']);

$ics = calendar(
    "BEGIN:VEVENT\r\nUID:eom\r\nDTSTART:20260131T130000Z\r\nDTEND:20260131T140000Z\r\n" .
    "RRULE:FREQ=MONTHLY;BYMONTHDAY=31\r\nSUMMARY:EOM\r\nEND:VEVENT"
);
check('BYMONTHDAY=31 skips short months rather than rolling over',
    parse($ics, '2026-01-01', '2026-05-01'),
    ['2026-01-31 08:00 EOM', '2026-03-31 09:00 EOM']);

// -- EXDATE and RECURRENCE-ID -----------------------------------------------------
$ics = calendar(
    "BEGIN:VEVENT\r\nUID:ex\r\nDTSTART:20260817T130000Z\r\nDTEND:20260817T133000Z\r\n" .
    "RRULE:FREQ=DAILY;COUNT=3\r\nEXDATE:20260818T130000Z\r\nSUMMARY:Standup\r\nEND:VEVENT"
);
check('EXDATE removes an occurrence',
    parse($ics, '2026-08-01', '2026-09-01'),
    ['2026-08-17 09:00 Standup', '2026-08-19 09:00 Standup']);

$ics = calendar(
    "BEGIN:VEVENT\r\nUID:mod\r\nDTSTART:20260817T130000Z\r\nDTEND:20260817T133000Z\r\n" .
    "RRULE:FREQ=DAILY;COUNT=3\r\nSUMMARY:Standup\r\nEND:VEVENT\r\n" .
    "BEGIN:VEVENT\r\nUID:mod\r\nRECURRENCE-ID:20260818T130000Z\r\nDTSTART:20260818T160000Z\r\n" .
    "DTEND:20260818T163000Z\r\nSUMMARY:Standup (moved)\r\nEND:VEVENT"
);
check('RECURRENCE-ID replaces the generated instance',
    parse($ics, '2026-08-01', '2026-09-01'),
    ['2026-08-17 09:00 Standup', '2026-08-18 12:00 Standup (moved)', '2026-08-19 09:00 Standup']);

// -- Window bounding --------------------------------------------------------------
// An open-ended daily rule from years ago must be skipped forward, not walked.
$ics = calendar(
    "BEGIN:VEVENT\r\nUID:old\r\nDTSTART:20050101T130000Z\r\nDTEND:20050101T133000Z\r\n" .
    "RRULE:FREQ=DAILY\r\nSUMMARY:Ancient\r\nEND:VEVENT"
);
$started = microtime(true);
// The window is half-open at 00:00 on the 20th, so the 09:00 occurrence that day
// starts past the end: the 17th, 18th and 19th remain.
$result = parse($ics, '2026-08-17', '2026-08-20');
$elapsed = microtime(true) - $started;
check('open-ended rule is bounded to the window', count($result), 3);
check('open-ended rule resolves quickly', $elapsed < 1.0, true);

// An event entirely outside the window is dropped.
$ics = calendar(
    "BEGIN:VEVENT\r\nUID:far\r\nDTSTART:20301017T130000Z\r\nDTEND:20301017T140000Z\r\nSUMMARY:Far\r\nEND:VEVENT"
);
check('event outside the window is dropped', parse($ics, '2026-08-01', '2026-09-01'), []);

// -- Escaping ---------------------------------------------------------------------
$ics = calendar(
    "BEGIN:VEVENT\r\nUID:esc\r\nDTSTART:20260817T140000Z\r\nDTEND:20260817T150000Z\r\n" .
    "SUMMARY:Budget\\, Q3\\; final\r\nEND:VEVENT"
);
check('escaped commas and semicolons are unescaped',
    parse($ics, '2026-08-01', '2026-09-01'),
    ['2026-08-17 10:00 Budget, Q3; final']);

printf("\n  %d passed, %d failed\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
