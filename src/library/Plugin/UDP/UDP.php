<?php

/**
 * UDP Social — FOSSBilling Servicecustom Plugin
 *
 * Handles lifecycle events for UDP Social service orders (L1 and L2).
 * Loaded by Servicecustom\Service::callOnAdapter().
 *
 * Constructor receives $plugin_config (from product.plugin_config JSON).
 * Action methods receive ($service_data, $order_data, $params).
 */
class UDP
{
    public function __construct(private readonly array $config = [])
    {
    }

    /**
     * Called when admin activates an order (after payment).
     *
     * Validates business rules, then triggers provisioning (manual notification
     * for now; API call once automation is ready).
     *
     * @throws \FOSSBilling\Exception on rule violations
     */
    public function activate(array $service, array $order, array $params = []): bool
    {
        // $service is Servicecustom\Service::toApiArray() — config keys are spread
        // into the top-level array, not nested under a 'config' key.
        $config = $service;
        $tier   = $config['tier'] ?? 'l1';

        $this->validateDomainConfig($config, $order);

        $config['provisioning_status'] = 'pending';
        $config['provisioning_requested_at'] = date('Y-m-d H:i:s');

        $this->updateServiceConfig($service['id'], $config);

        $yamlPath = $this->emitProvisioningConfig($config, $order);
        $this->notifyAdminProvisioningRequired($tier, $config, $order, $yamlPath);

        return true;
    }

    /**
     * Called on recurring renewal (L1 monthly charge processed).
     */
    public function renew(array $service, array $order, array $params = []): bool
    {
        // No provisioning action needed on renewal.
        return true;
    }

    /**
     * Called when service is suspended (e.g. payment failure).
     */
    public function suspend(array $service, array $order, array $params = []): bool
    {
        // TODO: signal instance suspension to Hetzner/Ansible layer.
        return true;
    }

    /**
     * Called when suspension is lifted.
     */
    public function unsuspend(array $service, array $order, array $params = []): bool
    {
        // TODO: signal instance unsuspension.
        return true;
    }

    /**
     * Called when an order is canceled.
     *
     * For subdomain orders: sets grace_period_ends_at (6 months from now,
     * provided the instance was active for at least 30 days).
     */
    public function cancel(array $service, array $order, array $params = []): bool
    {
        $config = $service;

        if (($config['domain_type'] ?? '') === 'subdomain') {
            $config['grace_period_ends_at'] = $this->calculateGracePeriodEnd(
                $config['provisioning_requested_at'] ?? null
            );
        }

        $config['canceled_at'] = date('Y-m-d H:i:s');
        $this->updateServiceConfig($service['id'], $config);

        // TODO: for L1, signal Hetzner deprovisioning after grace period expires.

        return true;
    }

    /**
     * Called when a canceled order is uncanceled (customer resubscribes).
     */
    public function uncancel(array $service, array $order, array $params = []): bool
    {
        $config = $service;
        unset($config['grace_period_ends_at'], $config['canceled_at']);
        $this->updateServiceConfig($service['id'], $config);

        return true;
    }

    /**
     * Called when the service record is permanently deleted.
     */
    public function delete(array $service, array $order, array $params = []): bool
    {
        // TODO: confirm VPS is deprovisioned (L1) before allowing deletion.
        return true;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Enforce domain config business rules before activation.
     *
     * @throws \FOSSBilling\Exception
     */
    private function validateDomainConfig(array $config, array $order): void
    {
        $domainType = $config['domain_type'] ?? '';

        if (!in_array($domainType, ['subdomain', 'byod'], true)) {
            throw new \FOSSBilling\Exception('Order is missing a valid domain type selection.');
        }

        if ($domainType === 'subdomain') {
            $label = $config['subdomain'] ?? '';
            if ($label === '') {
                throw new \FOSSBilling\Exception('Subdomain label is missing from order config.');
            }

            // L2 + subdomain requires an active Update Service subscription for this client.
            if (($config['tier'] ?? 'l1') === 'l2') {
                // TODO: query client_order to verify an active Update Service addon order exists.
                // Placeholder until we have product IDs to reference.
            }
        }

        if ($domainType === 'byod') {
            if (empty($config['custom_domain'])) {
                throw new \FOSSBilling\Exception('Custom domain is missing from order config.');
            }
        }
    }

    /**
     * Grace period is 6 months IF the instance was provisioned at least 30 days ago.
     * Otherwise no grace period (subdomain released immediately).
     */
    private function calculateGracePeriodEnd(?string $provisionedAt): ?string
    {
        if ($provisionedAt === null) {
            return null;
        }

        $provisionedTime = strtotime($provisionedAt);
        $thirtyDaysAgo   = strtotime('-30 days');

        if ($provisionedTime > $thirtyDaysAgo) {
            return null; // Not active long enough to earn grace period
        }

        return date('Y-m-d H:i:s', strtotime('+6 months'));
    }

    /**
     * Persist updated config back to service_custom via direct DB write.
     *
     * FOSSBilling plugins don't have DI container access, so we use a raw PDO
     * connection. This is consistent with how other FOSSBilling plugins operate.
     */
    private function updateServiceConfig(int $serviceId, array $config): void
    {
        // PATH_ROOT is defined by FOSSBilling bootstrap.
        require_once PATH_ROOT . '/load.php';

        $di = include PATH_ROOT . '/di.php';
        /** @var \Pimple\Container $di */
        $di['db']->exec(
            'UPDATE service_custom SET config = ?, updated_at = ? WHERE id = ?',
            [json_encode($config), date('Y-m-d H:i:s'), $serviceId]
        );
    }

    /**
     * Write a customer vars YAML file for use with provision.yml.
     *
     * Returns the path written, or null on failure.
     */
    private function emitProvisioningConfig(array $config, array $order): ?string
    {
        require_once PATH_ROOT . '/load.php';
        $di = include PATH_ROOT . '/di.php';

        $clientId = $order['client_id'] ?? null;
        $email = '';
        $name  = '';
        if ($clientId) {
            $client = $di['db']->getRow('SELECT first_name, last_name, email FROM client WHERE id = ?', [$clientId]);
            if ($client) {
                $name  = trim(($client['first_name'] ?? '') . ' ' . ($client['last_name'] ?? ''));
                $email = $client['email'] ?? '';
            }
        }

        $orderId  = $order['id'] ?? 'unknown';
        $isDomain = ($config['domain_type'] ?? '') === 'subdomain';
        $subdomain    = $config['subdomain'] ?? '';
        $customDomain = $config['custom_domain'] ?? '';
        $username = $config['customer_username'] ?? '';

        $lines   = [];
        $lines[] = '---';
        $lines[] = '# Auto-generated by UDP/FOSSBilling';
        $lines[] = sprintf('# Order: #%s | Generated: %s', $orderId, date('Y-m-d H:i:s'));
        $lines[] = '# Fill in vps_ip after creating the Hetzner VPS, then run:';
        $lines[] = sprintf('#   ansible-playbook provision.yml -i "<vps_ip>," -e customer_vars=<this_file>');
        $lines[] = '';

        if ($isDomain) {
            $lines[] = sprintf('customer_subdomain: "%s"', $subdomain);
        } else {
            $lines[] = sprintf('customer_custom_domain: "%s"', $customDomain);
        }

        $lines[] = sprintf('customer_username: "%s"', $username);
        $lines[] = sprintf('customer_email: "%s"', $email);
        $lines[] = sprintf('customer_name: "%s"', addslashes($name));
        $lines[] = '';
        $lines[] = 'vps_ip: ""   # ← fill in after VPS creation';

        $dir  = PATH_ROOT . '/data/provisioning';
        $path = $dir . '/customer_' . $orderId . '.yml';

        @mkdir($dir, 0755, true);
        $written = @file_put_contents($path, implode("\n", $lines) . "\n");

        return $written !== false ? $path : null;
    }

    /**
     * Send an admin notification that a new instance needs provisioning.
     * Pre-automation: this is the trigger for manual provisioning work.
     */
    private function notifyAdminProvisioningRequired(string $tier, array $config, array $order, ?string $yamlPath = null): void
    {
        $domain = ($config['domain_type'] === 'subdomain')
            ? ($config['subdomain'] . '.udp.social')
            : ($config['custom_domain'] ?? 'unknown');

        $subject = sprintf('[UDP] New %s order — provision %s', strtoupper($tier), $domain);
        $body    = sprintf(
            "Order #%s\nClient ID: %s\nTier: %s\nDomain: %s\n\nProvisioning required.",
            $order['id'] ?? '?',
            $order['client_id'] ?? '?',
            strtoupper($tier),
            $domain
        );

        if ($yamlPath) {
            $body .= "\n\nCustomer vars YAML: " . $yamlPath;
            $body .= "\n\nRun:\n  cd ~/udp/provisioning && ./provision.sh " . $yamlPath . " --skip-dns";
        }

        // TODO: replace with FOSSBilling email service once DI access is sorted.
        error_log($subject . "\n" . $body);
    }
}
