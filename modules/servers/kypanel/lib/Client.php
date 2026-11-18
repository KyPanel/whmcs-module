<?php

namespace KyPanel\Whmcs;

class Client
{
    private $base;
    private $token;
    private $verifyTls;

    // username => account id
    private $ids = [];

    public function __construct(array $params)
    {
        $host = trim($params['serverhostname'] ?? '') ?: trim($params['serverip'] ?? '');
        if ($host === '') {
            throw new \RuntimeException(Lang::t('The server has no hostname or IP address.'));
        }
        $port = (int) ($params['serverport'] ?? 0) ?: 7711;
        $this->base = 'https://' . $host . ':' . $port;
        $this->token = trim($params['serverpassword'] ?? '');
        if ($this->token === '') {
            throw new \RuntimeException(Lang::t('Paste a KyPanel API token in the server\'s Password field.'));
        }
        // allow self-signed certs when "Secure" is off
        $this->verifyTls = !empty($params['serversecure']);
    }

    public function get(string $path, array $query = [])
    {
        return $this->request('GET', $path, null, $query);
    }

    public function post(string $path, $body = null)
    {
        return $this->request('POST', $path, $body ?? new \stdClass());
    }

    public function put(string $path, $body)
    {
        return $this->request('PUT', $path, $body);
    }

    public function delete(string $path, array $query = [])
    {
        return $this->request('DELETE', $path, null, $query);
    }

    public function request(string $method, string $path, $body = null, array $query = [])
    {
        $url = $this->base . $path . ($query ? '?' . http_build_query($query) : '');
        $ch = curl_init($url);
        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Accept: application/json',
            'User-Agent: KyPanel-WHMCS/' . KYPANEL_WHMCS_VERSION,
        ];
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => $this->verifyTls,
            CURLOPT_SSL_VERIFYHOST => $this->verifyTls ? 2 : 0,
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        $this->log($method . ' ' . $path, $body, $code, $raw);

        if ($raw === false) {
            throw new \RuntimeException(Lang::t('Couldn\'t reach {server}: {error}', ['server' => $this->base, 'error' => $err]));
        }
        $data = $raw === '' ? [] : json_decode($raw, true);
        if ($code >= 200 && $code < 300) {
            return is_array($data) ? $data : [];
        }
        throw new \RuntimeException(self::problem($code, is_array($data) ? $data : []));
    }

    private static function problem(int $code, array $p): string
    {
        $msg = $p['title'] ?? ('HTTP ' . $code);
        if (!empty($p['detail'])) {
            $msg .= ': ' . $p['detail'];
        }
        if (!empty($p['fields']) && is_array($p['fields'])) {
            $parts = [];
            foreach ($p['fields'] as $field => $text) {
                $parts[] = $field . ': ' . $text;
            }
            $msg .= ' (' . implode('; ', $parts) . ')';
        }
        return $msg;
    }

    private function log(string $action, $request, int $code, $raw)
    {
        if (!function_exists('logModuleCall')) {
            return;
        }
        $req = is_array($request) ? $request : [];
        logModuleCall('kypanel', $action, $req, $code . ' ' . (is_string($raw) ? $raw : ''), null,
            array_filter([$this->token, $req['password'] ?? null, $req['new_password'] ?? null]));
    }

    public function accountId(string $username): string
    {
        $username = strtolower(trim($username));
        if ($username === '') {
            throw new \RuntimeException(Lang::t('The service has no username.'));
        }
        if (isset($this->ids[$username])) {
            return $this->ids[$username];
        }
        $page = $this->get('/api/v1/accounts', ['q' => $username, 'page' => 1, 'per_page' => 100]);
        foreach ($page['items'] ?? [] as $a) {
            if (strtolower($a['username'] ?? '') === $username) {
                return $this->ids[$username] = $a['id'];
            }
        }
        throw new \RuntimeException(Lang::t('There is no account named "{username}" on this KyPanel server.', ['username' => $username]));
    }

    public function account(string $username): array
    {
        return $this->get('/api/v1/accounts/' . rawurlencode($this->accountId($username)));
    }

    public function allAccounts(): array
    {
        $out = [];
        for ($page = 1; $page < 1000; $page++) {
            $res = $this->get('/api/v1/accounts', ['page' => $page, 'per_page' => 500]);
            $items = $res['items'] ?? [];
            $out = array_merge($out, $items);
            if (count($items) < 500) {
                break;
            }
        }
        return $out;
    }

    // accepts the package name or id
    public function packageId(string $nameOrId): string
    {
        $want = trim($nameOrId);
        if ($want === '') {
            throw new \RuntimeException(Lang::t('Choose a KyPanel package in the product\'s Module Settings.'));
        }
        $names = [];
        foreach ($this->get('/api/v1/packages')['items'] ?? [] as $p) {
            if ($p['id'] === $want || strcasecmp($p['name'], $want) === 0) {
                return $p['id'];
            }
            $names[] = $p['name'];
        }
        throw new \RuntimeException(Lang::t('There is no package "{package}" on this KyPanel server. Packages: {packages}.', [
            'package' => $want,
            'packages' => $names ? implode(', ', $names) : Lang::t('none yet'),
        ]));
    }

    // create/suspend/terminate run as jobs on the server, poll until done
    public function wait(string $jobId, int $seconds = 240)
    {
        if ($jobId === '') {
            return;
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit($seconds + 60);
        }
        $until = time() + $seconds;
        $delay = 1;
        do {
            $job = $this->get('/api/v1/jobs/' . rawurlencode($jobId))['job'] ?? [];
            switch ($job['status'] ?? '') {
                case 'succeeded':
                    return;
                case 'failed':
                case 'canceled':
                case 'cancelled':
                    throw new \RuntimeException($job['error'] ?? Lang::t('The KyPanel job failed.'));
            }
            sleep($delay);
            $delay = min($delay + 1, 5);
        } while (time() < $until);
        throw new \RuntimeException(Lang::t('KyPanel is still working on it (job {job}). Check Ky Admin before trying again.', ['job' => $jobId]));
    }
}
