<?php
// web/lib/context.php — resolves the effective configuration for a single request.
//
// index.php and calendar.php previously each implemented this chain separately, so a
// change to one could silently disagree with the other: the page would render with
// one timezone, label or horizon while its data arrived resolved under another.
//
// Precedence, lowest to highest:
//   1. config.php 'default' room
//   2. config.php room matching ?room=
//   3. rooms row in SQLite matching ?room=
//   4. users row matching ?userid= (which also forces the room context to 'personal')
//   5. URL parameters

require_once __DIR__ . '/db.php';

class LibreContext
{
    public string $roomId = 'default';
    public array $roomConfig = [];
    public bool $isDatabaseRoom = false;
    public bool $isPersonalizedUser = false;

    public string $lang = 'en';
    public string $view = 'room';
    public string $displayName = '';
    public string $timeFormat = 'auto';
    public string $timezone = 'UTC';

    public float $weatherLat = 43.65;
    public float $weatherLon = -79.38;
    public string $weatherCity = 'Toronto';

    public int $pastHorizon = 30;
    public int $futureHorizon = 30;

    public bool $showRss = true;
    public bool $showWeather = true;

    /** @var string[] Resolved feeds: room feeds, replaced by user feeds, plus ?cal= overrides. */
    public array $calendarUrls = [];

    /** True when the resolved feed list includes the bundled demo generator. */
    public bool $usingDemoCalendar = false;

    public const VIEWS = ['room', 'dashboard', 'grid'];

    private const DEFAULT_LAT = 43.65;
    private const DEFAULT_LON = -79.38;
    private const DEFAULT_CITY = 'Toronto';

    /**
     * @param array $query Request parameters, normally $_GET.
     */
    public static function resolve(array $config, LibreDb $db, array $query): self
    {
        $ctx = new self();

        $token = isset($query['userid']) && $query['userid'] !== '' ? (string)$query['userid'] : '';

        // A token always implies the personal context, whatever ?room= says.
        $ctx->roomId = $token !== ''
            ? 'personal'
            : (isset($query['room']) ? (string)$query['room'] : 'default');

        $room = $db->getRoomConfig($ctx->roomId);
        if ($room) {
            $ctx->isDatabaseRoom = true;
        } else {
            $room = $config['rooms'][$ctx->roomId] ?? $config['rooms']['default'] ?? [];
        }
        $ctx->roomConfig = $room;

        $ctx->lang = (string)($config['ui']['lang'] ?? 'en');
        $ctx->view = (string)($room['view'] ?? 'room');
        $ctx->displayName = (string)($room['display_name'] ?? '');
        $ctx->timeFormat = ((string)($room['time_format'] ?? '')) ?: 'auto';
        $ctx->timezone = ((string)($room['timezone'] ?? '')) ?: (string)($config['calendar']['timezone'] ?? 'UTC');
        $ctx->showRss = (bool)($room['show_rss'] ?? true);
        $ctx->showWeather = (bool)($room['show_weather'] ?? true);
        $ctx->pastHorizon = self::horizon($room['past_horizon'] ?? null);
        $ctx->futureHorizon = self::horizon($room['future_horizon'] ?? null);

        // getRoomConfig() casts absent coordinates to 0.0, which is a real point in the
        // Atlantic rather than "unset", so treat a null island as no coordinates.
        self::applyLocation($ctx, $room['weather_lat'] ?? null, $room['weather_lon'] ?? null, $room['weather_city'] ?? null);

        $user = null;
        if ($token !== '') {
            $stmt = $db->getPdo()->prepare(
                "SELECT view, time_format, timezone, weather_lat, weather_lon, weather_city,
                        display_name, past_horizon, future_horizon
                 FROM users WHERE access_token = ?"
            );
            $stmt->execute([$token]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if ($user) {
            $ctx->isPersonalizedUser = true;
            if (!empty($user['view'])) {
                $ctx->view = (string)$user['view'];
            }
            $ctx->displayName = (string)($user['display_name'] ?? '');
            $ctx->timeFormat = ((string)($user['time_format'] ?? '')) ?: 'auto';
            if (!empty($user['timezone'])) {
                $ctx->timezone = (string)$user['timezone'];
            }
            self::applyLocation($ctx, $user['weather_lat'] ?? null, $user['weather_lon'] ?? null, $user['weather_city'] ?? null);
            if (!empty($user['past_horizon'])) {
                $ctx->pastHorizon = self::horizon($user['past_horizon']);
            }
            if (!empty($user['future_horizon'])) {
                $ctx->futureHorizon = self::horizon($user['future_horizon']);
            }
        }

        // ---- URL overrides -------------------------------------------------------

        if (isset($query['lang'])) {
            $lang = (string)$query['lang'];
            if ($lang === 'en' || $lang === 'fr') {
                $ctx->lang = $lang;
            }
        }

        if (isset($query['view'])) {
            $view = (string)$query['view'];
            if ($view === '7daygrid') {
                $view = 'grid';
            }
            if (in_array($view, self::VIEWS, true)) {
                $ctx->view = $view;
            }
        }
        if (!in_array($ctx->view, self::VIEWS, true)) {
            $ctx->view = 'room';
        }

        if (isset($query['show_rss'])) {
            $ctx->showRss = (bool)$query['show_rss'];
        }
        if (isset($query['show_weather'])) {
            $ctx->showWeather = (bool)$query['show_weather'];
        }

        // ---- Feeds ---------------------------------------------------------------

        $urls = is_array($room['calendar_url'] ?? null)
            ? $room['calendar_url']
            : [$room['calendar_url'] ?? ''];
        $urls = array_values(array_filter(array_map('strval', $urls), static fn($u) => $u !== ''));

        // A user's own feeds replace the template's rather than adding to them.
        if ($token !== '') {
            $userUrls = $db->getCalendarsByToken($token);
            if (!empty($userUrls)) {
                $urls = $userUrls;
            }
        }

        // ?cal= is accepted only in the personal context, and only for remote URLs that
        // pass the SSRF gate. Config and database feeds are deliberately not filtered,
        // because they may legitimately name a local template.
        if ($ctx->roomId === 'personal' && !empty($query['cal'])) {
            $overrides = is_array($query['cal']) ? $query['cal'] : [$query['cal']];
            foreach ($overrides as $override) {
                $override = (string)$override;
                if (LibreDb::isValidRemoteUrl($override)) {
                    $urls[] = $override;
                }
            }
        }

        $ctx->calendarUrls = $urls;

        foreach ($urls as $url) {
            if (str_contains($url, 'demo.ics.php')) {
                $ctx->usingDemoCalendar = true;
                break;
            }
        }

        return $ctx;
    }

    private static function horizon($value): int
    {
        $days = (int)$value;
        return $days > 0 ? $days : 30;
    }

    private static function applyLocation(self $ctx, $lat, $lon, $city): void
    {
        $lat = (float)$lat;
        $lon = (float)$lon;
        if ($lat !== 0.0 || $lon !== 0.0) {
            $ctx->weatherLat = $lat;
            $ctx->weatherLon = $lon;
            $ctx->weatherCity = ((string)$city) ?: self::DEFAULT_CITY;
        } elseif (((string)$city) !== '') {
            $ctx->weatherCity = (string)$city;
        }
    }
}
