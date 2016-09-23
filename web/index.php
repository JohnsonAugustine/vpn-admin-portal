<?php
/**
 *  Copyright (C) 2016 SURFnet.
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as
 *  published by the Free Software Foundation, either version 3 of the
 *  License, or (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
require_once sprintf('%s/vendor/autoload.php', dirname(__DIR__));

use GuzzleHttp\Client;
use SURFnet\VPN\Admin\AdminPortalModule;
use SURFnet\VPN\Admin\GuzzleHttpClient;
use SURFnet\VPN\Admin\TwigFilters;
use SURFnet\VPN\Admin\TwigTpl;
use SURFnet\VPN\Common\Config;
use SURFnet\VPN\Common\HttpClient\CaClient;
use SURFnet\VPN\Common\HttpClient\ServerClient;
use SURFnet\VPN\Common\Http\FormAuthenticationHook;
use SURFnet\VPN\Common\Http\FormAuthenticationModule;
use SURFnet\VPN\Common\Http\MellonAuthenticationHook;
use SURFnet\VPN\Common\Http\Request;
use SURFnet\VPN\Common\Http\HtmlResponse;
use SURFnet\VPN\Common\Http\SecurityHeadersHook;
use SURFnet\VPN\Common\Http\Service;
use SURFnet\VPN\Common\Http\Session;
use SURFnet\VPN\Common\Logger;
use SURFnet\VPN\Common\Http\ReferrerCheckHook;

$logger = new Logger('vpn-admin-portal');

try {
    $request = new Request($_SERVER, $_GET, $_POST);
    $instanceId = $request->getServerName();

    $dataDir = sprintf('%s/data/%s', dirname(__DIR__), $instanceId);
    $config = Config::fromFile(sprintf('%s/config/%s/config.yaml', dirname(__DIR__), $instanceId));

    $templateDirs = [
        sprintf('%s/views', dirname(__DIR__)),
        sprintf('%s/config/%s/views', dirname(__DIR__), $instanceId),
    ];
    $serverMode = $config->v('serverMode');

    $templateCache = null;
    if ('production' === $serverMode) {
        // enable template cache when running in production mode
        $templateCache = sprintf('%s/tpl', $dataDir);
    }

    $tpl = new TwigTpl($templateDirs, $templateCache);
    $tpl->addFilter(TwigFilters::sizeToHuman());
    $tpl->setDefault(
        array(
            'requestUri' => $request->getUri(),
            'requestRoot' => $request->getRoot(),
            'requestRootUri' => $request->getRootUri(),
        )
    );

    $service = new Service();
    $service->addBeforeHook('referrer_check', new ReferrerCheckHook());
    $service->addAfterHook('security_headers', new SecurityHeadersHook());

    // Authentication
    $authMethod = $config->v('authMethod');
    $tpl->addDefault(array('authMethod' => $authMethod));

    switch ($authMethod) {
        case 'MellonAuthentication':
            $service->addBeforeHook(
                'auth',
                new MellonAuthenticationHook(
                    $config->v('MellonAuthentication', 'attribute')
                )
            );
            break;
        case 'FormAuthentication':
            $session = new Session(
                'vpn-admin-portal',
                array(
                    'secure' => 'development' !== $serverMode,
                )
            );
            $service->addBeforeHook(
                'auth',
                new FormAuthenticationHook(
                    $session,
                    $tpl
                )
            );
            $service->addModule(
                new FormAuthenticationModule(
                    $config->v('FormAuthentication'),
                    $session,
                    $tpl
                )
            );
            break;
        default:
            throw new RuntimeException('unsupported authentication mechanism');
    }

    // vpn-ca-api
    $guzzleCaClient = new GuzzleHttpClient(
        new Client([
            'defaults' => [
                'auth' => [
                    $config->v('apiProviders', 'vpn-ca-api', 'userName'),
                    $config->v('apiProviders', 'vpn-ca-api', 'userPass'),
                ],
            ],
        ])
    );
    $caClient = new CaClient($guzzleCaClient, $config->v('apiProviders', 'vpn-ca-api', 'apiUri'));

    // vpn-server-api
    $guzzleServerClient = new GuzzleHttpClient(
        new Client([
            'defaults' => [
                'auth' => [
                    $config->v('apiProviders', 'vpn-server-api', 'userName'),
                    $config->v('apiProviders', 'vpn-server-api', 'userPass'),
                ],
            ],
        ])
    );
    $serverClient = new ServerClient($guzzleServerClient, $config->v('apiProviders', 'vpn-server-api', 'apiUri'));

    $adminPortalModule = new AdminPortalModule(
        $tpl,
        $serverClient,
        $caClient
    );
    $service->addModule($adminPortalModule);

    $service->run($request)->send();
} catch (Exception $e) {
    $logger->error($e->getMessage());
    $response = new HtmlResponse($e->getMessage(), 500);
    $response->send();
}
