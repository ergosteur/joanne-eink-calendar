<?php
// web/demo.ics.php - Dynamically generated iCal for testing
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="demo.ics"');

echo "BEGIN:VCALENDAR\r\n";
echo "VERSION:2.0\r\n";
echo "PRODID:-//LibreJoanne//Demo Generator//EN\r\n";

$tz = new DateTimeZone('America/Toronto');
$start = new DateTime('now', $tz);
// Go back 2 days to show some history, go forward 14 days
$start->modify('-2 days');

for ($i = 0; $i < 16; $i++) {
    $dateStr = $start->format('Ymd');
    
    // 1. Morning Standup (09:00 - 09:30)
    echo "BEGIN:VEVENT\r\n";
    echo "UID:demo-standup-" . "{$dateStr}" . "\r\n";
    echo "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
    echo "DTSTART:{$dateStr}T090000\r\n";
    echo "DTEND:{$dateStr}T093000\r\n";
    echo "SUMMARY:Morning Standup\r\n";
    echo "END:VEVENT\r\n";

    // 2. Deep Work (14:00 - 16:00)
    echo "BEGIN:VEVENT\r\n";
    echo "UID:demo-work-" . "{$dateStr}" . "\r\n";
    echo "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
    echo "DTSTART:{$dateStr}T140000\r\n";
    echo "DTEND:{$dateStr}T160000\r\n";
    echo "SUMMARY:Deep Work Session\r\n";
    echo "END:VEVENT\r\n";

    $start->modify('+1 day');
}

echo "END:VCALENDAR\r\n";
