<?php
// scripts/test-context.php — assertions for request context resolution.
//
//   scripts/php scripts/test-context.php
//
// Run by scripts/smoke.sh. Focuses on the precedence chain and on URLs that are
// malformed in the ways hand-built device URLs actually are.

require_once __DIR__ . '/../web/lib/bootstrap.php';

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

$config = [
    'rooms' => [
        'default'  => ['name' => 'The Boardroom', 'view' => 'room', 'calendar_url' => ['demo.ics.php']],
        'personal' => ['name' => 'My Schedule', 'view' => 'dashboard', 'calendar_url' => ['demo.ics.php']],
    ],
    'calendar' => ['timezone' => 'America/Toronto', 'cache_ttl' => 30],
    'ui'       => ['lang' => 'fr'], // site default differs from the user's preference
    'security' => ['db_path' => ':memory:', 'encryption_key' => 'test-key'],
];

$db = new LibreDb($config);
$pdo = $db->getPdo();
$pdo->prepare("INSERT INTO users (username, access_token, view, lang, display_name, timezone)
               VALUES (?, ?, ?, ?, ?, ?)")
    ->execute(['john_smith', 'tok123', 'dashboard', 'en', '', 'America/Vancouver']);

const TOK = 'tok123';

echo "Context resolution\n";

$ctx = LibreContext::resolve($config, $db, ['userid' => TOK]);
check('valid token is recognised', $ctx->isPersonalizedUser, true);
check('user language beats the site default', $ctx->lang, 'en');
check('user timezone applies', $ctx->timezone, 'America/Vancouver');
check('valid token is not flagged invalid', $ctx->tokenInvalid, false);

// The reported bug: "?" typed where "&" belongs glues the query onto the token.
$ctx = LibreContext::resolve($config, $db, ['userid' => TOK . '?view=grid']);
check('token survives a "?" instead of "&"', $ctx->isPersonalizedUser, true);
check('preferences survive that typo', $ctx->lang, 'en');
check('the stranded parameter is applied', $ctx->view, 'grid');
check('recovered token is not flagged invalid', $ctx->tokenInvalid, false);

// Several stranded parameters.
$ctx = LibreContext::resolve($config, $db, ['userid' => TOK . '?view=grid&lang=fr&show_clock=0']);
check('multiple stranded parameters are recovered', $ctx->view, 'grid');
check('a stranded language override still wins', $ctx->lang, 'fr');
check('a stranded toggle is recovered', $ctx->showClock, false);

// A properly supplied parameter must not be displaced by a recovered one.
$ctx = LibreContext::resolve($config, $db, ['userid' => TOK . '?view=grid', 'view' => 'room']);
check('an explicit parameter beats a recovered one', $ctx->view, 'room');

// The same typo on ?room=.
$ctx = LibreContext::resolve($config, $db, ['room' => 'default?view=grid']);
check('room key survives the same typo', $ctx->roomId, 'default');
check('stranded parameter applies for rooms too', $ctx->view, 'grid');

// A genuinely unknown token must be reported, not silently defaulted.
$ctx = LibreContext::resolve($config, $db, ['userid' => 'nosuchtoken']);
check('unknown token is flagged', $ctx->tokenInvalid, true);
check('unknown token is not personalised', $ctx->isPersonalizedUser, false);
check('unknown token falls back to the site language', $ctx->lang, 'fr');

// No token at all is not an error.
$ctx = LibreContext::resolve($config, $db, []);
check('absent token is not flagged invalid', $ctx->tokenInvalid, false);
check('absent token uses the default room', $ctx->roomId, 'default');

printf("\n  %d passed, %d failed\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
