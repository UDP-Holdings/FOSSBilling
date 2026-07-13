<?php

/**
 * Copyright 2022-2025 FOSSBilling
 * Copyright 2011-2021 BoxBilling, Inc.
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace Box\Mod\Client\Controller;

class Client implements \FOSSBilling\InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function register(\Box_App &$app)
    {
        $app->get('/checkout', 'get_checkout', [], static::class);
        $app->get('/client/reset-password-confirm/:hash', 'get_reset_password_confirm', ['hash' => '[a-z0-9]+'], static::class);
        $app->get('/client', 'get_client_index', [], static::class);
        $app->get('/client/logout', 'get_client_logout', [], static::class);
        $app->get('/client/:page', 'get_client_page', ['page' => '[a-z0-9-]+'], static::class);
        $app->get('/client/confirm-email/:hash', 'get_client_confirmation', ['page' => '[a-z0-9-]+'], static::class);
    }

    public function get_client_index(\Box_App $app)
    {
        $this->di['is_client_logged'];

        return $app->render('mod_client_index');
    }

    public function get_client_confirmation(\Box_App $app, $hash): never
    {
        $service = $this->di['mod_service']('client');
        $service->approveClientEmailByHash($hash);
        $systemService = $this->di['mod_service']('System');
        $systemService->setPendingMessage(__trans('Email address was confirmed'));
        $app->redirect('/');
    }

    public function get_client_logout(\Box_App $app): never
    {
        $api = $this->di['api_client'];
        $api->profile_logout();
        $app->redirect('/');
    }

    /**
     * WordPress → FOSSBilling SSO handoff.
     *
     * Validates the signed wp_token issued by udp-billing-bridge.php, then
     * finds or auto-creates the FOSSBilling client record and establishes a
     * session. The optional ?plan= param is a product slug; if present the
     * customer is sent directly to that product's order page.
     */
    public function get_checkout(\Box_App $app): never
    {
        $token = $_GET['wp_token'] ?? '';
        $plan  = preg_replace('/[^a-z0-9-]/', '', strtolower($_GET['plan']  ?? ''));
        $promo = preg_replace('/[^A-Z0-9_]/', '', strtoupper($_GET['promo'] ?? ''));

        if (!$token) {
            $app->redirect('/login');
        }

        $service = $this->di['mod_service']('Client');

        try {
            $client = $service->loginClientFromWpToken($token);
        } catch (\Exception $e) {
            $this->di['mod_service']('System')->setPendingMessage($e->getMessage());
            $app->redirect('/login');
        }

        $oldSession = $this->di['session']->getId();
        session_regenerate_id();
        $this->di['session']->set('client_id', $client->id);
        $this->di['mod_service']('cart')->transferFromOtherSession($oldSession);

        $this->di['logger']->info('Client #%s logged in via WP token', $client->id);

        $redirect = $plan ? '/order/' . $plan : '/order';
        if ($promo !== '') {
            $redirect .= '?promocode=' . urlencode($promo);
        }
        $app->redirect($redirect);
    }

    public function get_client_page(\Box_App $app, $page)
    {
        $this->di['is_client_logged'];
        $template = 'mod_client_' . $page;

        return $app->render($template);
    }

    public function get_reset_password_confirm(\Box_App $app, $hash)
    {
        $service = $this->di['mod_service']('client');
        $this->di['events_manager']->fire(['event' => 'onBeforePasswordResetClient']);
        $data = [
            'hash' => $hash,
        ];
        $template = 'mod_client_set_new_password';

        // Call password_reset_valid function and if true, then render the template, otherwise redirect to the index page
        $result = $service->password_reset_valid($data);
        if ($result !== false) {
            return $app->render($template);
        } else {
            $app->redirect('/');
        }
    }
}
