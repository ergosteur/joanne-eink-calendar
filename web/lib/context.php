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

    /** The clock repaints the panel every minute; ?show_clock=0 turns that off. */
    public bool $showClock = true;

    /**
     * Account name of a tokenised user, title-cased. Used as the header label when no
     * display label has been set, so a panel still says whose schedule it shows.
     */
    public string $usernameLabel = '';

    /** Trade refresh frequency for battery life on panels. */
    public bool $powerSave = false;

    /** @var string[] Resolved feeds: room feeds, replaced by user feeds, plus ?cal= overrides. */
    public array $calendarUrls = [];

    /** True when the resolved feed list includes the bundled demo generator. */
    public bool $usingDemoCalendar = false;

    /**
     * A token was supplied but matched no account. The panel still renders, but it is
     * showing the default schedule rather than anyone's, so the UI says so instead of
     * looking as though it worked.
     */
    public bool $tokenInvalid = false;

    /**
     * A room key was asked for that exists neither in the database nor in config, so
     * the default room is standing in. Documented behaviour, but a panel pointed at a
     * mistyped key should still say so rather than look correct.
     */
    public bool $roomFallback = false;

    public const VIEWS = ['room', 'dashboard', 'grid'];
    public const LANGUAGES = ['en', 'fr'];

    private const DEFAULT_LAT = 43.65;
    private const DEFAULT_LON = -79.38;
    private const DEFAULT_CITY = 'Toronto';

    /**
     * @param array $query Request parameters, normally $_GET.
     */
    public static function resolve(array $config, LibreDb $db, array $query): self
    {
        $ctx = new self();

        $query = self::recoverStrandedParams($query);

        $token = isset($query['userid']) && $query['userid'] !== '' ? (string)$query['userid'] : '';

        // A token always implies the personal context, whatever ?room= says.
        $ctx->roomId = $token !== ''
            ? 'personal'
            : (isset($query['room']) ? (string)$query['room'] : 'default');

        $room = $db->getRoomConfig($ctx->roomId);
        if ($room) {
            $ctx->isDatabaseRoom = true;
        } else {
            if (!isset($config['rooms'][$ctx->roomId]) && isset($query['room'])) {
                $ctx->roomFallback = true;
            }
            $room = $config['rooms'][$ctx->roomId] ?? $config['rooms']['default'] ?? [];
        }
        $ctx->roomConfig = $room;

        $ctx->lang = (string)($config['ui']['lang'] ?? 'en');
        // Empty means "follow the site default" rather than naming a language.
        if (in_array((string)($room['lang'] ?? ''), self::LANGUAGES, true)) {
            $ctx->lang = (string)$room['lang'];
        }
        $ctx->view = (string)($room['view'] ?? 'room');
        $ctx->displayName = (string)($room['display_name'] ?? '');
        $ctx->timeFormat = ((string)($room['time_format'] ?? '')) ?: 'auto';
        $ctx->timezone = ((string)($room['timezone'] ?? '')) ?: (string)($config['calendar']['timezone'] ?? 'UTC');
        $ctx->showRss = (bool)($room['show_rss'] ?? true);
        $ctx->showClock = (bool)($room['show_clock'] ?? true);
        $ctx->showWeather = (bool)($room['show_weather'] ?? true);
        $ctx->pastHorizon = self::horizon($room['past_horizon'] ?? null);
        $ctx->futureHorizon = self::horizon($room['future_horizon'] ?? null);

        // getRoomConfig() casts absent coordinates to 0.0, which is a real point in the
        // Atlantic rather than "unset", so treat a null island as no coordinates.
        self::applyLocation($ctx, $room['weather_lat'] ?? null, $room['weather_lon'] ?? null, $room['weather_city'] ?? null);

        $user = null;
        if ($token !== '') {
            $stmt = $db->getPdo()->prepare(
                "SELECT username, view, time_format, timezone, lang, show_clock,
                        weather_lat, weather_lon, weather_city,
                        display_name, past_horizon, future_horizon
                 FROM users WHERE access_token = ?"
            );
            $stmt->execute([$token]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $ctx->tokenInvalid = $user === null;
        }

        if ($user) {
            $ctx->isPersonalizedUser = true;
            if (!empty($user['view'])) {
                $ctx->view = (string)$user['view'];
            }
            $ctx->displayName = (string)($user['display_name'] ?? '');
            $ctx->usernameLabel = self::titleCase((string)($user['username'] ?? ''));
            $ctx->timeFormat = ((string)($user['time_format'] ?? '')) ?: 'auto';
            // NULL means the preference was never set, which reads as the default.
            if ($user['show_clock'] !== null) {
                $ctx->showClock = (bool)$user['show_clock'];
            }
            if (!empty($user['timezone'])) {
                $ctx->timezone = (string)$user['timezone'];
            }
            // Empty means "follow the site default" rather than a language.
            if (in_array((string)($user['lang'] ?? ''), self::LANGUAGES, true)) {
                $ctx->lang = (string)$user['lang'];
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

        if (isset($query['lang']) && in_array((string)$query['lang'], self::LANGUAGES, true)) {
            $ctx->lang = (string)$query['lang'];
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
            $ctx->showRss = self::flag($query['show_rss']);
        }
        if (isset($query['show_weather'])) {
            $ctx->showWeather = self::flag($query['show_weather']);
        }
        if (isset($query['show_clock'])) {
            $ctx->showClock = self::flag($query['show_clock']);
        }
        if (isset($query['power_save'])) {
            $ctx->powerSave = self::flag($query['power_save']);
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

    /**
     * Recover parameters stranded by a "?" used where "&" belongs.
     *
     * Device URLs are assembled by hand, and
     *     ?userid=abc123?view=grid
     * makes PHP read the token as "abc123?view=grid". Nothing matches, and the panel
     * quietly renders a generic view that looks perfectly fine — the failure is
     * invisible precisely where it matters most.
     *
     * The value is split at the first separator and the remainder parsed back into the
     * query. Parameters supplied properly keep precedence over recovered ones.
     */
    private static function recoverStrandedParams(array $query): array
    {
        foreach (['userid', 'room'] as $key) {
            if (!isset($query[$key]) || !is_string($query[$key])) {
                continue;
            }
            $parts = preg_split('/[?&]/', $query[$key], 2);
            if (count($parts) !== 2 || $parts[1] === '') {
                continue;
            }
            $stranded = [];
            parse_str($parts[1], $stranded);
            $query[$key] = $parts[0];
            if ($stranded) {
                $query = $query + $stranded; // existing keys win
            }
        }
        return $query;
    }

    /**
     * Interpret a URL toggle. A bare (bool) cast treated "false" and "off" as true,
     * because any non-empty string is truthy.
     */
    private static function flag($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return !in_array(strtolower(trim((string)$value)), ['0', 'false', 'no', 'off', ''], true);
    }

    /**
     * "john_smith" becomes "John Smith". Separators are treated as word breaks so an
     * account name reads as a name rather than as a login.
     */
    private static function titleCase(string $value): string
    {
        $value = trim(str_replace(['_', '-', '.'], ' ', $value));
        if ($value === '') {
            return '';
        }
        return ucwords(mb_strtolower($value, 'UTF-8'));
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
