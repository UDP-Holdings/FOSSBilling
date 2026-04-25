<?php
/**
 * UDP Social — FOSSBilling dev seed script.
 * Idempotent: safe to run on an already-seeded DB.
 * Run via: ddev exec php /var/www/html/src/seed_udp.php
 */

$pdo = new PDO('mysql:host=db;dbname=fossbilling;charset=utf8mb4', 'root', 'root');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ── Admin account ────────────────────────────────────────────────────────────
$hash = password_hash('Admin123', PASSWORD_DEFAULT, ['cost' => 12]);
$pdo->prepare("
    INSERT INTO admin (role, admin_group_id, email, pass, name, protected, status, created_at, updated_at)
    VALUES ('admin', 1, 'admin@udp.social', :hash, 'Administrator', 1, 'active', NOW(), NOW())
    ON DUPLICATE KEY UPDATE pass = :hash, updated_at = NOW()
")->execute([':hash' => $hash]);
echo "Admin: admin@udp.social / password\n";

// ── Currency ─────────────────────────────────────────────────────────────────
$pdo->exec("
    INSERT IGNORE INTO currency (id, title, code, conversion_rate, format, price_format, is_default, created_at, updated_at)
    VALUES (1, 'US Dollar', 'USD', 1.0000, '\${price}', 2, 1, NOW(), NOW())
");
echo "Currency: USD\n";

// ── Product category ─────────────────────────────────────────────────────────
$pdo->exec("
    INSERT IGNORE INTO product_category (id, title, description, created_at, updated_at)
    VALUES (1, 'UDP Social', 'Managed Friendica instances', NOW(), NOW())
");
echo "Product category: UDP Social\n";

// ── Helper: upsert a product_payment row, return its id ──────────────────────
function upsertPayment(PDO $pdo, array $fields): int {
    // Build SET clause for ON DUPLICATE KEY UPDATE
    $cols = implode(', ', array_map(fn($k) => "`$k`=:$k", array_keys($fields)));
    $pdo->prepare("
        INSERT INTO product_payment ($cols) VALUES ($cols)
        ON DUPLICATE KEY UPDATE $cols
    ")->execute($fields);
    return (int)$pdo->lastInsertId();
}

// ── Products ─────────────────────────────────────────────────────────────────
$productsData = [
    // [id, title, type, status, is_addon, setup, payment_fields, addons_json]
    [
        'id' => 1, 'title' => 'Domain Registration', 'type' => 'domain',
        'status' => 'disabled', 'is_addon' => 0, 'setup' => 'after_payment',
        'payment' => ['type' => 'free', 'once_price' => 0.00],
        'addons' => null,
    ],
    [
        'id' => 2, 'title' => 'Managed Friendica (L1)', 'type' => 'custom',
        'status' => 'enabled', 'is_addon' => 0, 'setup' => 'after_payment',
        'payment' => [
            'type'       => 'recurrent',
            'm_price'    => 15.00, 'm_enabled'  => 1,
            'a_price'    => 150.00, 'a_enabled' => 0,
        ],
        'addons' => null,
    ],
    [
        'id' => 3, 'title' => 'Update Service', 'type' => 'custom',
        'status' => 'enabled', 'is_addon' => 1, 'setup' => 'after_payment',
        'payment' => [
            'type'       => 'recurrent',
            'm_price'    => 3.00,  'm_enabled'  => 1,
            'a_price'    => 30.00, 'a_enabled'  => 1,
        ],
        'addons' => null,
    ],
    [
        'id' => 4, 'title' => 'Configured Handoff (L2)', 'type' => 'custom',
        'status' => 'enabled', 'is_addon' => 0, 'setup' => 'after_payment',
        'payment' => ['type' => 'once', 'once_price' => 75.00],
        'addons' => '[3]',
    ],
];

foreach ($productsData as $p) {
    // Insert or update product_payment
    $pp = $p['payment'];
    $ppBase = [
        'type'           => $pp['type'],
        'once_price'     => $pp['once_price']  ?? 0.00,
        'once_setup_price' => 0.00,
        'm_price'        => $pp['m_price']     ?? 0.00,
        'm_setup_price'  => 0.00,
        'm_enabled'      => $pp['m_enabled']   ?? 0,
        'a_price'        => $pp['a_price']     ?? 0.00,
        'a_setup_price'  => 0.00,
        'a_enabled'      => $pp['a_enabled']   ?? 0,
    ];

    // Check if a payment row for this product already exists
    $existingPayId = $pdo->query(
        "SELECT product_payment_id FROM product WHERE id = {$p['id']}"
    )->fetchColumn();

    if ($existingPayId) {
        // Update existing payment row
        $sets = implode(', ', array_map(fn($k) => "`$k`=:$k", array_keys($ppBase)));
        $stmt = $pdo->prepare("UPDATE product_payment SET $sets WHERE id = :pid");
        $ppBase[':pid'] = $existingPayId;
        $stmt->execute($ppBase);
        $payId = $existingPayId;
    } else {
        // Insert new payment row
        $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($ppBase)));
        $params = implode(', ', array_map(fn($k) => ":$k", array_keys($ppBase)));
        $pdo->prepare("INSERT INTO product_payment ($cols) VALUES ($params)")->execute($ppBase);
        $payId = (int)$pdo->lastInsertId();
    }

    // Upsert product
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($p['title'])), '-');
    $pdo->prepare("
        INSERT INTO product
            (id, product_category_id, product_payment_id, type, title, slug, status, is_addon, setup, addons, config, created_at, updated_at)
        VALUES
            (:id, 1, :pay_id, :type, :title, :slug, :status, :is_addon, :setup, :addons, '{}', NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            title=:title, slug=:slug, status=:status, is_addon=:is_addon, product_payment_id=:pay_id,
            addons=:addons, updated_at=NOW()
    ")->execute([
        ':id'       => $p['id'],
        ':pay_id'   => $payId,
        ':type'     => $p['type'],
        ':title'    => $p['title'],
        ':slug'     => $slug,
        ':status'   => $p['status'],
        ':is_addon' => $p['is_addon'],
        ':setup'    => $p['setup'],
        ':addons'   => $p['addons'],
    ]);
    echo "Product {$p['id']}: {$p['title']}\n";
}

// ── Payment gateways ─────────────────────────────────────────────────────────
$pdo->exec("UPDATE pay_gateway SET enabled=0 WHERE id IN (1,2)");

$stripeConfig = json_encode([
    'test_pub_key' => '***REMOVED***STRIPE_TEST_PK_REDACTED',
    'test_api_key' => 'STRIPE_TEST_SK_REDACTED',
]);
$pdo->prepare("
    INSERT INTO pay_gateway (id, name, gateway, enabled, test_mode, config, accepted_currencies, allow_single, allow_recurrent)
    VALUES (3, 'Credit Card', 'Stripe', 1, 1, :config, 'USD', 1, 1)
    ON DUPLICATE KEY UPDATE name='Credit Card', enabled=1, test_mode=1, config=:config
")->execute([':config' => $stripeConfig]);
echo "Gateway: Stripe (Credit Card, test mode)\n";

// ── System settings ──────────────────────────────────────────────────────────
$settings = [
    ['company_name',            'UDP Social'],
    ['company_email',           'admin@udp.social'],
    ['url',                     'https://fossbilling.dev.ddev.site/'],
    ['invoice_starting_number', '1'],
    ['checkout_tos',            'off'],
    ['theme',                   'udp'],
];
$stmtSys = $pdo->prepare("
    INSERT INTO setting (param, value, created_at, updated_at)
    VALUES (:param, :value, NOW(), NOW())
    ON DUPLICATE KEY UPDATE value=:value, updated_at=NOW()
");
foreach ($settings as [$param, $value]) {
    $stmtSys->execute([':param' => $param, ':value' => $value]);
}
echo "System settings applied\n";

echo "\nSeed complete.\n";
