<?php

/**
 * @package     Supertext Translation for PrestaShop
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

use Supertext\PrestaShop\Api\Reporting;
use Supertext\PrestaShop\Settings;

/**
 * The public ping endpoint of the Supertext Commando Center:
 * GET index.php?fc=module&module=supertext&controller=ping&challenge=<c>
 *
 * No login, no session, no cart, no redirects: it answers before FrontController::init() runs.
 * The answer (status, headers, body) is exactly the SDK's: 200 {"ok":true,"plugin":…,"challenge":…},
 * 400 for a bad challenge, 404 while version reports are off. Nothing else is returned.
 */
class SupertextPingModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $auth = false;

    /** @var bool */
    public $ssl = false;

    public function init()
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            http_response_code(405);
            header('Allow: GET');
            header('Cache-Control: no-store');
            exit;
        }

        $challenge = $_GET['challenge'] ?? null;
        $ping      = Reporting::ping(is_string($challenge) ? $challenge : null, Settings::versionReportsEnabled());

        if (!headers_sent()) {
            header_remove('Set-Cookie');
            http_response_code($ping->status);

            foreach ($ping->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }

        echo $ping->body;
        exit;
    }
}
