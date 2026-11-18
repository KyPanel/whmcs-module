<?php
// KyPanel provisioning module for WHMCS
// Server config: hostname, API token in the Password field, port 7711.

use WHMCS\Database\Capsule;
use WHMCS\Service\Status;
use KyPanel\Whmcs\Client;
use KyPanel\Whmcs\Lang;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

if (!defined('KYPANEL_WHMCS_VERSION')) {
    define('KYPANEL_WHMCS_VERSION', '1.0.0');
}

require_once __DIR__ . '/lib/Lang.php';
require_once __DIR__ . '/lib/Client.php';

function kypanel_MetaData()
{
    return [
        'DisplayName' => 'KyPanel',
        'APIVersion' => '1.1',
        'RequiresServer' => true,
        'DefaultNonSSLPort' => '7711',
        'DefaultSSLPort' => '7711',
        'ServiceSingleSignOnLabel' => Lang::t('Log in to KyPanel'),
        'ListAccountsUniqueIdentifierDisplayName' => Lang::t('Username'),
        'ListAccountsUniqueIdentifierField' => 'username',
        'ListAccountsProductField' => 'configoption1',
    ];
}

function kypanel_ConfigOptions()
{
    return [
        'package' => [
            'FriendlyName' => Lang::t('Package'),
            'Type' => 'text',
            'Size' => '30',
            'Description' => Lang::t('Name of the package in Ky Admin › Packages'),
        ],
        'reseller' => [
            'FriendlyName' => Lang::t('Reseller'),
            'Type' => 'yesno',
            'Description' => Lang::t('Create the account as a reseller'),
        ],
    ];
}

function kypanel_run(array $params, callable $fn)
{
    try {
        $fn(new Client($params));
        return 'success';
    } catch (\Throwable $e) {
        return $e->getMessage();
    }
}

function kypanel_CreateAccount(array $params)
{
    return kypanel_run($params, function (Client $c) use ($params) {
        $body = [
            'username' => strtolower($params['username']),
            'domain' => strtolower($params['domain']),
            'password' => $params['password'],
            'contact_email' => $params['clientsdetails']['email'] ?? '',
            'package_id' => $c->packageId($params['configoption1'] ?? ''),
        ];
        if (($params['configoption2'] ?? '') === 'on') {
            $body['make_reseller'] = true;
        }
        $res = $c->post('/api/v1/accounts', $body);
        $c->wait($res['job_id'] ?? '');
    });
}

function kypanel_SuspendAccount(array $params)
{
    return kypanel_run($params, function (Client $c) use ($params) {
        $reason = trim($params['suspendreason'] ?? '') ?: Lang::t('Suspended by billing');
        $res = $c->post('/api/v1/accounts/' . rawurlencode($c->accountId($params['username'])) . '/suspend', ['reason' => $reason]);
        $c->wait($res['job_id'] ?? '');
    });
}

function kypanel_UnsuspendAccount(array $params)
{
    return kypanel_run($params, function (Client $c) use ($params) {
        $res = $c->post('/api/v1/accounts/' . rawurlencode($c->accountId($params['username'])) . '/unsuspend');
        $c->wait($res['job_id'] ?? '');
    });
}

function kypanel_TerminateAccount(array $params)
{
    return kypanel_run($params, function (Client $c) use ($params) {
        $user = strtolower($params['username']);
        $res = $c->delete('/api/v1/accounts/' . rawurlencode($c->accountId($user)), ['confirm' => $user]);
        $c->wait($res['job_id'] ?? '');
    });
}

function kypanel_ChangePassword(array $params)
{
    return kypanel_run($params, function (Client $c) use ($params) {
        $c->post('/api/v1/accounts/' . rawurlencode($c->accountId($params['username'])) . '/password', ['new_password' => $params['password']]);
    });
}

function kypanel_ChangePackage(array $params)
{
    return kypanel_run($params, function (Client $c) use ($params) {
        $res = $c->put('/api/v1/accounts/' . rawurlencode($c->accountId($params['username'])) . '/package', [
            'package_id' => $c->packageId($params['configoption1'] ?? ''),
        ]);
        $c->wait($res['job_id'] ?? '');
    });
}

function kypanel_TestConnection(array $params)
{
    try {
        $c = new Client($params);
        $c->get('/api/v1/packages');
        $c->get('/api/v1/accounts', ['page' => 1, 'per_page' => 1]);
        return ['success' => true, 'error' => ''];
    } catch (\Throwable $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// daily cron
function kypanel_UsageUpdate(array $params)
{
    try {
        $c = new Client($params);
        foreach ($c->allAccounts() as $a) {
            Capsule::table('tblhosting')
                ->where('server', $params['serverid'])
                ->where('username', $a['username'])
                ->update([
                    'diskusage' => (int) ($a['disk_used_mb'] ?? 0),
                    'disklimit' => (int) ($a['disk_quota_mb'] ?? 0),
                    'bwusage' => (int) ($a['bw_used_month_mb'] ?? 0),
                    'bwlimit' => (int) ($a['bw_limit_mb'] ?? 0),
                    'lastupdate' => Capsule::raw('now()'),
                ]);
        }
    } catch (\Throwable $e) {
        if (function_exists('logModuleCall')) {
            logModuleCall('kypanel', __FUNCTION__, ['serverid' => $params['serverid'] ?? ''], $e->getMessage());
        }
        return $e->getMessage();
    }
    return 'success';
}

// Utilities > Server Sync
function kypanel_ListAccounts(array $params)
{
    try {
        $c = new Client($params);
        $accounts = [];
        foreach ($c->allAccounts() as $a) {
            $accounts[] = [
                'email' => $a['contact_email'] ?? '',
                'username' => $a['username'],
                'domain' => $a['main_domain'] ?? '',
                'uniqueIdentifier' => $a['username'],
                'product' => $a['package_name'] ?? '',
                'primaryip' => $a['ip_address'] ?? '',
                'created' => date('Y-m-d H:i:s', (int) (($a['created_at'] ?? 0) / 1000)),
                'status' => ($a['status'] ?? '') === 'suspended' ? Status::SUSPENDED : Status::ACTIVE,
            ];
        }
        return ['success' => true, 'accounts' => $accounts];
    } catch (\Throwable $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function kypanel_ServiceSingleSignOn(array $params)
{
    try {
        $c = new Client($params);
        $res = $c->post('/api/v1/accounts/' . rawurlencode($c->accountId($params['username'])) . '/sso', ['panel' => 'user']);
        return ['success' => true, 'redirectTo' => $res['url']];
    } catch (\Throwable $e) {
        return ['success' => false, 'errorMsg' => $e->getMessage()];
    }
}

function kypanel_AdminServicesTabFields(array $params)
{
    try {
        $a = (new Client($params))->account($params['username']);
    } catch (\Throwable $e) {
        return ['KyPanel' => htmlspecialchars($e->getMessage())];
    }
    $mb = function ($used, $limit) {
        return Lang::t('{used} MB of {limit}', [
            'used' => (int) $used,
            'limit' => (int) $limit > 0 ? (int) $limit . ' MB' : Lang::t('unlimited'),
        ]);
    };
    $status = htmlspecialchars(Lang::t($a['status'] ?? ''));
    if (!empty($a['suspend_reason'])) {
        $status .= ' (' . htmlspecialchars($a['suspend_reason']) . ')';
    }
    return [
        Lang::t('KyPanel account') => htmlspecialchars($a['username'] . ' · ' . ($a['main_domain'] ?? '')),
        Lang::t('Status') => $status,
        Lang::t('Package') => htmlspecialchars($a['package_name'] ?? ''),
        Lang::t('Disk') => $mb($a['disk_used_mb'] ?? 0, $a['disk_quota_mb'] ?? 0),
        Lang::t('Bandwidth this month') => $mb($a['bw_used_month_mb'] ?? 0, $a['bw_limit_mb'] ?? 0),
    ];
}

function kypanel_ClientArea(array $params)
{
    return [
        'templatefile' => 'clientarea',
        'vars' => [
            'kypanelSsoUrl' => 'clientarea.php?action=productdetails&id=' . (int) $params['serviceid'] . '&dosinglesignon=1',
            'kypanelSsoLabel' => Lang::t('Log in to KyPanel'),
        ],
    ];
}
