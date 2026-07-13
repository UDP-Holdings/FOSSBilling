<?php
/**
 * UDP Social — FOSSBilling seed script.
 * Idempotent: safe to run on an already-seeded DB.
 *
 * Dev:  ddev exec php /var/www/html/src/seed_udp.php
 * Live: php /var/www/fossbilling/seed_udp.php
 */

// Read DB credentials from FOSSBilling config.php
$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    die("config.php not found at $configFile\n");
}
$config = require $configFile;

$dbHost = $config['db']['host']     ?? 'localhost';
$dbName = $config['db']['name']     ?? 'fossbilling';
$dbUser = $config['db']['user']     ?? 'root';
$dbPass = $config['db']['password'] ?? '';

$pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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

// ── Products ─────────────────────────────────────────────────────────────────
$productsData = [
    [
        'id' => 1, 'title' => 'Domain Registration', 'type' => 'domain',
        'status' => 'disabled', 'is_addon' => 0, 'setup' => 'after_payment',
        'payment' => ['type' => 'free', 'once_price' => 0.00],
        'addons' => null,
    ],
    // Standard tier: Docker/OVH shared box. Cheaper, good for most families.
    [
        'id' => 5, 'title' => 'Managed Friendica', 'type' => 'custom',
        'status' => 'enabled', 'is_addon' => 0, 'setup' => 'after_payment',
        'plugin' => 'UDP', 'plugin_config' => '{"tier":"docker"}',
        'payment' => [
            'type'    => 'recurrent',
            'm_price' => 10.00, 'm_enabled' => 1,
        ],
        'addons' => null,
    ],
    // Dedicated tier: YunoHost/Hetzner isolated VPS. More privacy, larger groups.
    [
        'id' => 2, 'title' => 'Managed Friendica (Dedicated)', 'type' => 'custom',
        'status' => 'enabled', 'is_addon' => 0, 'setup' => 'after_payment',
        'plugin' => 'UDP', 'plugin_config' => '{"server_type":"cpx11","tier":"dedicated"}',
        'payment' => [
            'type'    => 'recurrent',
            'm_price' => 20.00, 'm_enabled' => 1,
        ],
        'addons' => null,
    ],
    [
        'id' => 3, 'title' => 'Managed Friendica (Dedicated Pro)', 'type' => 'custom',
        'status' => 'enabled', 'is_addon' => 0, 'setup' => 'after_payment',
        'plugin' => 'UDP', 'plugin_config' => '{"server_type":"cpx21","tier":"dedicated-pro"}',
        'payment' => [
            'type'    => 'recurrent',
            'm_price' => 35.00, 'm_enabled' => 1,
        ],
        'addons' => null,
    ],
    [
        'id' => 4, 'title' => 'Managed Friendica (Dedicated Gold)', 'type' => 'custom',
        'status' => 'enabled', 'is_addon' => 0, 'setup' => 'after_payment',
        'plugin' => 'UDP', 'plugin_config' => '{"server_type":"cpx31","tier":"dedicated-gold"}',
        'payment' => [
            'type'    => 'recurrent',
            'm_price' => 50.00, 'm_enabled' => 1,
        ],
        'addons' => null,
    ],
];

foreach ($productsData as $p) {
    $pp = $p['payment'];
    $ppBase = [
        'type'             => $pp['type'],
        'once_price'       => $pp['once_price']  ?? 0.00,
        'once_setup_price' => 0.00,
        'm_price'          => $pp['m_price']     ?? 0.00,
        'm_setup_price'    => 0.00,
        'm_enabled'        => $pp['m_enabled']   ?? 0,
        'a_price'          => $pp['a_price']     ?? 0.00,
        'a_setup_price'    => 0.00,
        'a_enabled'        => $pp['a_enabled']   ?? 0,
    ];

    $existingPayId = $pdo->query(
        "SELECT product_payment_id FROM product WHERE id = {$p['id']}"
    )->fetchColumn();

    if ($existingPayId) {
        $sets = implode(', ', array_map(fn($k) => "`$k`=:$k", array_keys($ppBase)));
        $stmt = $pdo->prepare("UPDATE product_payment SET $sets WHERE id = :pid");
        $ppBase[':pid'] = $existingPayId;
        $stmt->execute($ppBase);
        $payId = $existingPayId;
    } else {
        $cols   = implode(', ', array_map(fn($k) => "`$k`", array_keys($ppBase)));
        $params = implode(', ', array_map(fn($k) => ":$k", array_keys($ppBase)));
        $pdo->prepare("INSERT INTO product_payment ($cols) VALUES ($params)")->execute($ppBase);
        $payId = (int)$pdo->lastInsertId();
    }

    $slug         = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($p['title'])), '-');
    $plugin       = $p['plugin']        ?? null;
    $pluginConfig = $p['plugin_config'] ?? null;
    $pdo->prepare("
        INSERT INTO product
            (id, product_category_id, product_payment_id, type, title, slug, status, is_addon, setup, addons, config, plugin, plugin_config, created_at, updated_at)
        VALUES
            (:id, 1, :pay_id, :type, :title, :slug, :status, :is_addon, :setup, :addons, '{}', :plugin, :plugin_config, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            title=:title, slug=:slug, status=:status, is_addon=:is_addon, product_payment_id=:pay_id,
            addons=:addons, plugin=:plugin, updated_at=NOW()
    ")->execute([
        ':id'            => $p['id'],
        ':pay_id'        => $payId,
        ':type'          => $p['type'],
        ':title'         => $p['title'],
        ':slug'          => $slug,
        ':status'        => $p['status'],
        ':is_addon'      => $p['is_addon'],
        ':setup'         => $p['setup'],
        ':addons'        => $p['addons'],
        ':plugin'        => $plugin,
        ':plugin_config' => $pluginConfig,
    ]);
    echo "Product {$p['id']}: {$p['title']}\n";
}

echo "\nSeed complete.\n";
