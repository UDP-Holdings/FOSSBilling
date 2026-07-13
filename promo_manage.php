<?php
/**
 * UDP promo code management script.
 *
 * Usage:
 *   php promo_manage.php list
 *   php promo_manage.php create      --code=ALPHA2026 --desc="Alpha invite" --months=3 [--maxuses=50]
 *   php promo_manage.php create-lock --code=BUSYBEES-LOCK --desc="..." [--maxuses=150]
 *   php promo_manage.php activate    --code=BUSYBEES-LOCK
 *   php promo_manage.php deactivate  --code=BUSYBEES-LOCK
 *   php promo_manage.php delete      --code=BUSYBEES-LOCK
 *
 * "create" — alpha/free-period pattern: type=percentage, value=100 (fully free),
 *   recurring=1, end_at = now + --months. Normal billing resumes after expiry.
 *
 * "create-lock" — price-lock pattern: type=absolute, value=5.00, recurring=1,
 *   no expiry. Locks the customer at $10/month when the base product is $15.
 *   Apply to grandfathered customers when their free period expires. Deactivate
 *   manually when ready to move them to standard pricing.
 *
 * ── BUSYBEES setup (two-code approach) ───────────────────────────────────────
 *
 * The live BUSYBEES code (absolute $30, non-recurring) is WRONG — FOSSBilling
 * caps the discount at the invoice total with no carryover, so it only gives
 * $10 off one invoice, not 3 months free.
 *
 * To fix, run on the live server:
 *   php promo_manage.php delete --code=BUSYBEES
 *   php promo_manage.php create --code=BUSYBEES --desc="Alpha invite — 3 months free" --months=3 --maxuses=150
 *   php promo_manage.php create-lock --code=BUSYBEES-LOCK --desc="Grandfathered $10/month" --maxuses=150
 *
 * At signup: customer uses BUSYBEES (3 months free).
 * When their free period expires: admin applies BUSYBEES-LOCK to their account
 * so renewals bill at $10/month instead of $15.
 *
 * Run on the live server:
 *   ssh -i ~/.ssh/udp_admin root@udp.social "php /var/www/fossbilling/promo_manage.php list"
 */

$config_path = __DIR__ . '/src/config.php';
if (!file_exists($config_path)) {
    // Live server path
    $config_path = '/var/www/fossbilling/config.php';
}

$c   = include $config_path;
$pdo = new PDO(
    'mysql:host=' . $c['db']['host'] . ';dbname=' . $c['db']['name'] . ';charset=utf8mb4',
    $c['db']['user'],
    $c['db']['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// ── Parse CLI args ────────────────────────────────────────────────────────────

$cmd  = $argv[1] ?? 'list';
$opts = [];
for ($i = 2; $i < $argc; $i++) {
    if (preg_match('/^--([a-z_]+)=(.*)$/', $argv[$i], $m)) {
        $opts[$m[1]] = $m[2];
    }
}

// ── Commands ──────────────────────────────────────────────────────────────────

match ($cmd) {
    'list'        => cmd_list($pdo),
    'create'      => cmd_create($pdo, $opts),
    'create-lock' => cmd_create_lock($pdo, $opts),
    'activate'    => cmd_set_active($pdo, $opts, 1),
    'deactivate'  => cmd_set_active($pdo, $opts, 0),
    'delete'      => cmd_delete($pdo, $opts),
    default       => die("Unknown command: $cmd\nSee file header for usage.\n"),
};

// ── Implementations ───────────────────────────────────────────────────────────

function cmd_list(PDO $pdo): void
{
    $rows = $pdo->query(
        'SELECT id, code, description, type, value, maxuses, used,
                recurring, active, end_at, created_at
         FROM promo ORDER BY id'
    )->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        echo "No promo codes found.\n";
        return;
    }

    $fmt = "%-5s %-20s %-8s %-8s %-6s %-8s %-6s %-10s %-12s %s\n";
    printf($fmt, 'ID', 'CODE', 'TYPE', 'VALUE', 'RECUR', 'USES', 'ACTIVE', 'EXPIRES', 'CREATED', 'DESCRIPTION');
    echo str_repeat('-', 110) . "\n";
    foreach ($rows as $r) {
        printf($fmt,
            $r['id'],
            $r['code'],
            $r['type'],
            $r['value'],
            $r['recurring'] ? 'yes' : 'no',
            $r['used'] . '/' . ($r['maxuses'] ?: '∞'),
            $r['active'] ? 'YES' : 'no',
            $r['end_at'] ? substr($r['end_at'], 0, 10) : 'never',
            substr($r['created_at'] ?? '', 0, 10),
            $r['description'] ?? ''
        );
    }
}

function cmd_create(PDO $pdo, array $opts): void
{
    $code   = strtoupper(trim($opts['code'] ?? ''));
    $desc   = $opts['desc'] ?? 'Alpha invite — 3 months free';
    $months = (int)($opts['months'] ?? 3);
    $max    = (int)($opts['maxuses'] ?? 0);

    if ($code === '') {
        die("--code is required\n");
    }

    $exists = $pdo->prepare('SELECT id FROM promo WHERE code = ?');
    $exists->execute([$code]);
    if ($exists->fetch()) {
        die("Code '$code' already exists. Use activate/deactivate to change its state.\n");
    }

    $end_at  = (new DateTimeImmutable())->modify("+{$months} months")->format('Y-m-d H:i:s');
    $product = json_encode([5]); // managed-friendica (Standard tier only)
    $now     = date('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        'INSERT INTO promo
            (code, description, type, value, maxuses, used, freesetup,
             once_per_client, recurring, active, products, end_at, created_at, updated_at)
         VALUES
            (:code, :desc, "percentage", 100.00, :maxuses, 0, 1,
             0, 1, 1, :products, :end_at, :now, :now)'
    );
    $stmt->execute([
        ':code'     => $code,
        ':desc'     => $desc,
        ':maxuses'  => $max,
        ':products' => $product,
        ':end_at'   => $end_at,
        ':now'      => $now,
    ]);

    echo "Created promo code: $code\n";
    echo "  100% off, recurring, freesetup, product=managed-friendica (id=5)\n";
    echo "  Expires: $end_at\n";
    echo "  Max uses: " . ($max ?: 'unlimited') . "\n";
    echo "  Active: YES\n";
}

function cmd_create_lock(PDO $pdo, array $opts): void
{
    $code = strtoupper(trim($opts['code'] ?? ''));
    $desc = $opts['desc'] ?? 'Grandfathered $10/month price lock';
    $max  = (int)($opts['maxuses'] ?? 0);

    if ($code === '') {
        die("--code is required\n");
    }

    $exists = $pdo->prepare('SELECT id FROM promo WHERE code = ?');
    $exists->execute([$code]);
    if ($exists->fetch()) {
        die("Code '$code' already exists. Use activate/deactivate to change its state.\n");
    }

    $product = json_encode([5]); // managed-friendica (Standard tier only)
    $now     = date('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        'INSERT INTO promo
            (code, description, type, value, maxuses, used, freesetup,
             once_per_client, recurring, active, products, end_at, created_at, updated_at)
         VALUES
            (:code, :desc, "absolute", 5.00, :maxuses, 0, 1,
             0, 1, 1, :products, NULL, :now, :now)'
    );
    $stmt->execute([
        ':code'     => $code,
        ':desc'     => $desc,
        ':maxuses'  => $max,
        ':products' => $product,
        ':now'      => $now,
    ]);

    echo "Created price-lock promo code: $code\n";
    echo "  \$5.00 absolute off, recurring forever, product=managed-friendica (id=5)\n";
    echo "  Effective price: \$10/month when base product is \$15\n";
    echo "  Max uses: " . ($max ?: 'unlimited') . "\n";
    echo "  Active: YES\n";
}

function cmd_set_active(PDO $pdo, array $opts, int $active): void
{
    $code = strtoupper(trim($opts['code'] ?? ''));
    if ($code === '') { die("--code is required\n"); }

    $stmt = $pdo->prepare('UPDATE promo SET active = ?, updated_at = NOW() WHERE code = ?');
    $stmt->execute([$active, $code]);

    if ($stmt->rowCount() === 0) {
        echo "No promo found with code '$code'.\n";
    } else {
        echo "Code '$code' " . ($active ? 'activated' : 'deactivated') . ".\n";
    }
}

function cmd_delete(PDO $pdo, array $opts): void
{
    $code = strtoupper(trim($opts['code'] ?? ''));
    if ($code === '') { die("--code is required\n"); }

    $stmt = $pdo->prepare('DELETE FROM promo WHERE code = ?');
    $stmt->execute([$code]);

    if ($stmt->rowCount() === 0) {
        echo "No promo found with code '$code'.\n";
    } else {
        echo "Deleted promo code '$code'.\n";
    }
}
