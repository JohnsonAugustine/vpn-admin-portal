<?php
/**
 * Copyright 2016 François Kooman <fkooman@tuxed.net>.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace fkooman\VPN\AdminPortal;

use fkooman\Http\RedirectResponse;
use fkooman\Http\Request;
use fkooman\Rest\Service;
use fkooman\Rest\ServiceModuleInterface;
use fkooman\Tpl\TemplateManagerInterface;

class AdminPortalModule implements ServiceModuleInterface
{
    /** @var \fkooman\Tpl\TemplateManagerInterface */
    private $templateManager;

    /** @var VpnServerApiClient */
    private $vpnServerApiClient;

    /** @var VpnCaApiClient */
    private $vpnCaApiClient;

    public function __construct(TemplateManagerInterface $templateManager, VpnServerApiClient $vpnServerApiClient, VpnCaApiClient $vpnCaApiClient)
    {
        $this->templateManager = $templateManager;
        $this->vpnServerApiClient = $vpnServerApiClient;
        $this->vpnCaApiClient = $vpnCaApiClient;
    }

    public function init(Service $service)
    {
        $service->get(
            '/',
            function (Request $request) {
                return new RedirectResponse($request->getUrl()->getRootUrl().'connections', 302);
            }
        );

        $service->get(
            '/connections',
            function (Request $request) {
                // get the fancy pool name
                $serverPools = $this->vpnServerApiClient->getServerPools();
                $idNameMapping = [];
                foreach ($serverPools as $pool) {
                    $idNameMapping[$pool['id']] = $pool['name'];
                }

                return $this->templateManager->render(
                    'vpnConnections',
                    array(
                        'idNameMapping' => $idNameMapping,
                        'connections' => $this->vpnServerApiClient->getConnections(),
                    )
                );
            }
        );

        $service->get(
            '/info',
            function (Request $request) {
                return $this->templateManager->render(
                    'vpnInfo',
                    array(
                        'serverPools' => $this->vpnServerApiClient->getServerPools(),
                    )
                );
            }
        );

        $service->get(
            '/users',
            function (Request $request) {
                $certList = $this->vpnCaApiClient->getCertList();
                $disabledUsers = $this->vpnServerApiClient->getDisabledUsers();

                $userIdList = [];
                foreach ($certList['items'] as $certEntry) {
                    $userId = $certEntry['user_id'];
                    if (!in_array($userId, $userIdList)) {
                        $userIdList[] = $userId;
                    }
                }

                $userList = [];
                foreach ($userIdList as $userId) {
                    $userList[] = [
                        'userId' => $userId,
                        'isDisabled' => in_array($userId, $disabledUsers),
                    ];
                }

                return $this->templateManager->render(
                    'vpnUserList',
                    array(
                        'userList' => $userList,
                    )
                );
            }
        );

        $service->get(
            '/users/:userId',
            function (Request $request, $userId) {
                // XXX validate userId
                $userCertList = $this->vpnCaApiClient->getCertList($userId);
                $disabledCommonNames = $this->vpnServerApiClient->getDisabledCommonNames();

                $userConfigList = [];
                foreach ($userCertList['items'] as $userCert) {
                    $commonName = sprintf('%s_%s', $userCert['user_id'], $userCert['name']);
                    // only if state is valid it makes sense to show disable
                    if ('V' === $userCert['state']) {
                        if (in_array($commonName, $disabledCommonNames)) {
                            $userCert['state'] = 'D';
                        }
                    }

                    $userConfigList[] = $userCert;
                }

                return $this->templateManager->render(
                    'vpnUserConfigList',
                    array(
                        'userId' => $userId,
                        'userConfigList' => $userConfigList,
                        'hasOtpSecret' => $this->vpnServerApiClient->getHasOtpSecret($userId),
                        'isDisabled' => $this->vpnServerApiClient->getIsDisabledUser($userId),
                    )
                );
            }
        );

        $service->post(
            '/users/:userId',
            function (Request $request, $userId) {
                // XXX validate userId

                // XXX is casting to bool appropriate for checkbox?
                $disable = (bool) $request->getPostParameter('disable');
                // XXX is casting to bool appropriate for checkbox?
                $deleteOtpSecret = (bool) $request->getPostParameter('otp_secret');

                if ($disable) {
                    $this->vpnServerApiClient->disableUser($userId);
                } else {
                    $this->vpnServerApiClient->enableUser($userId);
                }

                // XXX we also have to kill all active clients for this userId if
                // we disable the user!
                // XXX multi instance?!

                if ($deleteOtpSecret) {
                    $this->vpnServerApiClient->deleteOtpSecret($userId);
                }

                $returnUrl = sprintf('%susers', $request->getUrl()->getRootUrl(), $userId);

                return new RedirectResponse($returnUrl);
            }
        );

        $service->get(
            '/users/:userId/:configName',
            function (Request $request, $userId, $configName) {
                // XXX validate userId
                // XXX validate configName

                $disabledCommonNames = $this->vpnServerApiClient->getDisabledCommonNames();
                $commonName = sprintf('%s_%s', $userId, $configName);

                return $this->templateManager->render(
                    'vpnUserConfig',
                    array(
                        'userId' => $userId,
                        'configName' => $configName,
                        'isDisabled' => in_array($commonName, $disabledCommonNames),
                    )
                );
            }
        );

        $service->post(
            '/users/:userId/:configName',
            function (Request $request, $userId, $configName) {
                // XXX validate userId
                // XXX validate configName
                $commonName = sprintf('%s_%s', $userId, $configName);

                // XXX is casting to bool appropriate for checkbox?
                $disable = (bool) $request->getPostParameter('disable');

                if ($disable) {
                    $this->vpnServerApiClient->disableCommonName($commonName);
                } else {
                    $this->vpnServerApiClient->enableCommonName($commonName);
                }

                $this->vpnServerApiClient->killCommonName($commonName);

                $returnUrl = sprintf('%susers/%s', $request->getUrl()->getRootUrl(), $userId);

                return new RedirectResponse($returnUrl);
            }
        );

        $service->get(
            '/documentation',
            function (Request $request) {
                return $this->templateManager->render(
                    'vpnDocumentation',
                    array()
                );
            }
        );

        $service->get(
            '/log',
            function (Request $request) {
                $showDate = $request->getUrl()->getQueryParameter('showDate');
                if (is_null($showDate)) {
                    $showDate = date('Y-m-d');
                }

                // XXX validate date, backend will take care of it as well, so not
                // the most important here...

                return $this->templateManager->render(
                    'vpnLog',
                    array(
                        'minDate' => date('Y-m-d', strtotime('today -31 days')),
                        'maxDate' => date('Y-m-d', strtotime('today')),
                        'showDate' => $showDate,
                        'log' => $this->vpnServerApiClient->getLog($showDate),
                    )
                );
            }
        );
    }
}
