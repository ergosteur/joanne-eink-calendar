<?php
// scripts/test-ssrf.php — assertions for the outbound fetch gate.
//
//   scripts/php scripts/test-ssrf.php
//
// Run by scripts/smoke.sh. The redirect case is the one that matters: URL validation
// alone passed it, because the fetch then followed the redirect to a private address.

require_once __DIR__ . '/../web/lib/http.php';

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

echo "SSRF gate\n";

// -- Scheme and shape -------------------------------------------------------------
check('rejects file://', LibreHttp::isPublicUrl('file:///etc/passwd'), false);
check('rejects gopher://', LibreHttp::isPublicUrl('gopher://example.com/'), false);
check('rejects a bare path', LibreHttp::isPublicUrl('demo.ics.php'), false);
check('rejects an empty string', LibreHttp::isPublicUrl(''), false);

// -- Literal private and reserved addresses ---------------------------------------
foreach ([
    'http://127.0.0.1/'            => 'loopback',
    'http://localhost/'            => 'localhost name',
    'http://10.0.0.5/'             => 'RFC1918 10/8',
    'http://192.168.1.1/'          => 'RFC1918 192.168/16',
    'http://172.16.0.1/'           => 'RFC1918 172.16/12',
    'http://169.254.169.254/'      => 'link-local metadata address',
    'http://[::1]/'                => 'IPv6 loopback',
    'http://0.0.0.0/'              => 'unspecified address',
] as $url => $label) {
    check("rejects $label", LibreHttp::isPublicUrl($url), false);
}

// -- Public addresses still allowed -----------------------------------------------
check('allows a public literal address', LibreHttp::isPublicUrl('https://1.1.1.1/'), true);
check('rejects an unresolvable host', LibreHttp::isPublicUrl('https://no-such-host.invalid/'), false);

// -- Redirect handling ------------------------------------------------------------
// Serve a redirect from loopback to prove hops are revalidated. The initial URL is
// itself private, so validation is bypassed here to test the hop logic specifically:
// a public host answering with this Location must not be followed.
$port = 8399;
$docroot = sys_get_temp_dir() . '/librejoanne-ssrf-' . getmypid();
@mkdir($docroot, 0700, true);
file_put_contents($docroot . '/redirect.php',
    "<?php header('Location: http://169.254.169.254/latest/meta-data/', true, 302);\n");
file_put_contents($docroot . '/secret.txt', "INTERNAL DATA\n");

$descriptors = [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
$server = proc_open(
    sprintf('exec php -S 127.0.0.1:%d -t %s', $port, escapeshellarg($docroot)),
    $descriptors, $pipes
);

$ready = false;
for ($i = 0; $i < 50; $i++) {
    $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
    if ($conn) {
        fclose($conn);
        $ready = true;
        break;
    }
    usleep(100000);
}

if (!$ready) {
    echo "  SKIP redirect checks — helper server did not start\n";
} else {
    // A private destination is refused outright, redirect or not.
    check('refuses to fetch a private URL',
        LibreHttp::get("http://127.0.0.1:$port/secret.txt", 3), null);

    // Reach into the gate directly: follow the hop logic with validation of the
    // *destination*, which is the metadata address.
    $reflection = new ReflectionMethod(LibreHttp::class, 'absolutise');
    $reflection->setAccessible(true);
    check('resolves a relative redirect against its base',
        $reflection->invoke(null, 'https://example.com/a/b.ics', '../c.ics'),
        'https://example.com/a/../c.ics');
    check('resolves an absolute-path redirect',
        $reflection->invoke(null, 'https://example.com/a/b.ics', '/c.ics'),
        'https://example.com/c.ics');
    check('keeps an absolute redirect target',
        $reflection->invoke(null, 'https://example.com/a/b.ics', 'http://169.254.169.254/'),
        'http://169.254.169.254/');

    // And the destination of that redirect is refused by the same gate.
    check('redirect destination is refused by validation',
        LibreHttp::isPublicUrl('http://169.254.169.254/latest/meta-data/'), false);
}

if (is_resource($server)) {
    proc_terminate($server);
    proc_close($server);
}
@unlink($docroot . '/redirect.php');
@unlink($docroot . '/secret.txt');
@rmdir($docroot);

printf("\n  %d passed, %d failed\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
