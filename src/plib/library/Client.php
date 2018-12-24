<?php
/**
 * Copyright 2018-2019 Michael Dekker
 *
 * Permission is hereby granted, free of charge, to any person
 * obtaining a copy of this software and associated documentation
 * files (the "Software"), to deal in the Software without restriction,
 * including without limitation the rights to use, copy, modify, merge,
 * publish, distribute, sublicense, and/or sell copies of the Software,
 * and to permit persons to whom the Software is furnished to do so,
 * subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included
 * in all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS
 * OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL
 * THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS
 * IN THE SOFTWARE.
 *
 * @copyright 2018-2019 Michael Dekker
 * @author    Michael Dekker <info@trendweb.io>
 * @license   MIT
 */

/**
 * Class Module_Cloudflaredns_Client
 */
class Modules_Cloudflaredns_Client
{
    const BASE_URL = 'https://api.cloudflare.com/client/v4/';

    const SERVICE_VERSION = '1.6.9.1';

    private $email;
    private $key;

    public static $zones = [];

    /**
     * Singleton
     *
     * Modules_Cloudflaredns_Client constructor.
     *
     * @param string $email
     * @param string $key
     */
    protected function __construct($email, $key)
    {
        $this->email = $email;
        $this->key = $key;
    }

    /**
     * Get an instance of the Cloudflare Client
     *
     * @param string $email
     * @param string $apiKey
     *
     * @return static|null
     */
    public static function getInstance($email = '', $apiKey = '')
    {
        static $instance = null;
        if ($instance === null || $email) {
            if (!$email) {
                $email = pm_Settings::get(Modules_Cloudflaredns_Form_Settings::USERNAME);
            }
            if (!$apiKey) {
                $apiKey = pm_Settings::get(Modules_Cloudflaredns_Form_Settings::API_KEY);
            }
            $instance = new static($email, $apiKey);
        }

        return $instance;
    }

    /**
     * Get all domain names for the Cloudflare account
     *
     * @return string[]
     * @throws Exception
     */
    public function getDomainNames()
    {
        if (static::$zones) {
            return array_values(static::$zones);
        }

        $curl = curl_init(static::BASE_URL.'zones');
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_HTTPHEADER     => [
                "X-Auth-Key: {$this->key}",
                "X-Auth-Email: {$this->email}",
            ],
        ]);
        $result = @json_decode(curl_exec($curl), true);

        if (!isset($result['result'])) {
            throw new Exception('Cloudflare Error: '.json_encode($result));
        }

        static::$zones = array_combine(array_column($result['result'], 'id'), array_column($result['result'], 'name'));

        return array_column($result['result'], 'name');
    }

    /**
     * @param $domains
     *
     * @throws Zend_Db_Adapter_Exception
     * @throws Zend_Db_Statement_Exception
     * @throws pm_Exception
     * @throws Exception
     */
    public function syncDomains($domains)
    {
        $cloudflareDomains = $this->getDomainNames();
        $domains = array_intersect($domains, $cloudflareDomains);
        $proxied = (bool) pm_Settings::get(Modules_Cloudflaredns_Form_Settings::PROXIED);
        foreach ($domains as $domain) {
            // Detect updated records
            $pleskDomain = pm_Domain::getByName($domain);
            $pleskRecords = static::getPleskDnsEntries($domain);

            $cloudflareRecords = static::getCloudflareDnsEntries($domain);
            $newRecords = [];
            foreach ($pleskRecords as $id => $pleskRecord) {
                $name = rtrim(static::formatNameForCloudflare($domain, $pleskRecord['name']), '.');
                $type = $pleskRecord['type'];

                $found = false;
                foreach ($cloudflareRecords as $cloudflareRecord) {
                    if ($cloudflareRecord['name'] === $name && $cloudflareRecord['type'] === $type) {
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    $newRecords[] = $pleskRecord;
                }
            }

            // Detect updated domains and change them in Cloudflare
            $updatedRecords = [];
            foreach (static::getSavedDnsEntries($pleskDomain->getName()) as $savedId => $savedRecord) {
                foreach ($pleskRecords as $pleskRecord) {
                    if ($pleskRecord['name'] === $savedRecord['name']
                        && $pleskRecord['type'] === $savedRecord['type']
                        && $pleskRecord['content'] !== $savedRecord['content']
                    ) {
                        $updatedRecord = false;
                        foreach ($cloudflareRecords as $cloudflareRecord) {
                            if ($cloudflareRecord['name'] === rtrim($pleskRecord['name'], '.')
                                && $cloudflareRecord['type'] === $pleskRecord['type']
                            ) {
                                $updatedRecord = $pleskRecord;
                                $updatedRecord['cloudflare_id'] = $cloudflareRecord['cloudflare_id'];
                                break;
                            }
                        }
                        if ($updatedRecord) {
                            $updatedRecords[] = $updatedRecord;
                        }
                        continue 2;
                    } elseif ($pleskRecord['name'] === $savedRecord['name']
                        && $pleskRecord['type'] === $savedRecord['type']
                        && $pleskRecord['content'] === $savedRecord['content']
                    ) {
                        continue 2;
                    }
                }
            }

            // Detect removed domains and remove them from Cloudflare as well
            $pleskRecordIds = array_keys($pleskRecords);
            $removedRecords = [];
            foreach (static::getSavedDnsEntries($pleskDomain->getName()) as $savedId => $savedRecord) {
                if (!in_array($savedId, $pleskRecordIds)) {
                    foreach ($cloudflareRecords as $cloudflareRecord) {
                        if ($cloudflareRecord['name'] === rtrim($savedRecord['name'], '.')
                            && $cloudflareRecord['type'] === $savedRecord['type']
                        ) {
                            $removedRecord = $savedRecord;
                            $removedRecord['cloudflare_id'] = $cloudflareRecord['cloudflare_id'];
                            $removedRecords[] = $removedRecord;
                            break;
                        }
                    }
                }
            }

            $zones = array_flip($this->getZones());
            $mh = curl_multi_init();
            $handles = [];

            // Upload new to Cloudflare
            foreach ($newRecords as $newRecord) {
                $ch = curl_init(static::BASE_URL."zones/{$zones[$domain]}/dns_records");
                curl_setopt_array($ch, [
                    CURLOPT_HTTPHEADER     => [
                        "X-Auth-Email: {$this->email}",
                        "X-Auth-Key: {$this->key}",
                        'Content-Type: application/json;charset=UTF-8',
                    ],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST           => 1,
                    CURLOPT_POSTFIELDS     => json_encode([
                        'type'    => $newRecord['type'],
                        'name'    => $newRecord['name'],
                        'content' => $newRecord['content'],
                        'ttl'     => (int) Modules_Cloudflaredns_Form_Settings::getTtl($pleskDomain->getId()),
                        'proxied' => $proxied,
                    ]),
                ]);
                $handles[] = $ch;
            }
            foreach ($handles as $ch) {
                curl_multi_add_handle($mh, $ch);
            }

            do {
                $mrc = curl_multi_exec($mh, $active);
            } while ($mrc == CURLM_CALL_MULTI_PERFORM);

            while ($active && $mrc == CURLM_OK) {
                if (curl_multi_select($mh) != -1) {
                    do {
                        $mrc = curl_multi_exec($mh, $active);
                    } while ($mrc == CURLM_CALL_MULTI_PERFORM);
                }
            }

            $results = [];
            foreach ($handles as $ch) {
                $results[] = curl_multi_getcontent($ch);
                curl_multi_remove_handle($mh, $ch);
            }
            curl_multi_close($mh);

            // Upload updated to Cloudflare
            $mh = curl_multi_init();
            $handles = [];
            foreach ($updatedRecords as $updatedRecord) {
                $ch = curl_init(static::BASE_URL."zones/{$zones[$domain]}/dns_records/{$updatedRecord['cloudflare_id']}");
                curl_setopt_array($ch, [
                    CURLOPT_HTTPHEADER     => [
                        "X-Auth-Email: {$this->email}",
                        "X-Auth-Key: {$this->key}",
                        'Content-Type: application/json;charset=UTF-8',
                    ],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CUSTOMREQUEST  => 'PUT',
                    CURLOPT_POSTFIELDS     => json_encode([
                        'type'    => $updatedRecord['type'],
                        'name'    => $updatedRecord['name'],
                        'content' => $updatedRecord['content'],
                        'ttl'     => (int) Modules_Cloudflaredns_Form_Settings::getTtl($pleskDomain->getId()),
                    ]),
                ]);
                $handles[] = $ch;
            }
            foreach ($handles as $ch) {
                curl_multi_add_handle($mh, $ch);
            }

            do {
                $mrc = curl_multi_exec($mh, $active);
            } while ($mrc == CURLM_CALL_MULTI_PERFORM);
            while ($active && $mrc == CURLM_OK) {
                if (curl_multi_select($mh) != -1) {
                    do {
                        $mrc = curl_multi_exec($mh, $active);
                    } while ($mrc == CURLM_CALL_MULTI_PERFORM);
                }
            }
            $results = [];
            foreach ($handles as $ch) {
                $results[] = curl_multi_getcontent($ch);
                curl_multi_remove_handle($mh, $ch);
            }
            curl_multi_close($mh);

            // Remove from Cloudflare
            $mh = curl_multi_init();
            $handles = [];
            foreach ($removedRecords as $removedRecord) {
                $ch = curl_init(static::BASE_URL."zones/{$zones[$domain]}/dns_records/{$removedRecord['cloudflare_id']}");
                curl_setopt_array($ch, [
                    CURLOPT_HTTPHEADER     => [
                        "X-Auth-Email: {$this->email}",
                        "X-Auth-Key: {$this->key}",
                    ],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CUSTOMREQUEST  => 'DELETE',
                ]);
                $handles[] = $ch;
            }
            foreach ($handles as $ch) {
                curl_multi_add_handle($mh, $ch);
            }

            do {
                $mrc = curl_multi_exec($mh, $active);
            } while ($mrc == CURLM_CALL_MULTI_PERFORM);

            while ($active && $mrc == CURLM_OK) {
                if (curl_multi_select($mh) != -1) {
                    do {
                        $mrc = curl_multi_exec($mh, $active);
                    } while ($mrc == CURLM_CALL_MULTI_PERFORM);
                }
            }

            $results = [];
            foreach ($handles as $ch) {
                $results[] = curl_multi_getcontent($ch);
                curl_multi_remove_handle($mh, $ch);
            }
            curl_multi_close($mh);

            /** @var array $entry */
            $this->setDomainInfo($pleskDomain->getName(), array_map(function ($entry) {
                return [
                    'name'    => $entry['name'],
                    'type'    => $entry['type'],
                    'content' => $entry['content'],
                ];
            }, $pleskRecords));
        }
    }

    /**
     * Return saved DNS entries
     *
     * @param string $id ID = Domain name
     *
     * @return array
     *
     * @throws pm_Exception
     */
    public static function getSavedDnsEntries($id)
    {
        return static::getDnsEntries($id, false);
    }

    /**
     * Return Plesk DNS entries
     *
     * @param string $id ID = Domain name
     *
     * @return array
     *
     * @throws pm_Exception
     */
    public static function getPleskDnsEntries($id)
    {
        return static::getDnsEntries($id, true);
    }

    /**
     * @param string $id ID = Domain name
     *
     * @return array
     *
     * @throws Exception
     */
    public static function getCloudflareDnsEntries($id)
    {
        return static::getInstance()->cloudflareDnsEntries($id);
    }

    /**
     * @param string $domainName ID = Domain name
     *
     * @return array
     *
     * @throws Exception
     */
    public function cloudflareDnsEntries($domainName)
    {
        $zoneId = array_flip($this->getZones())[$domainName];
        if (!$zoneId) {
            throw new Exception('Cloudflare domain not found');
        }

        $curl = curl_init(static::BASE_URL."zones/{$zoneId}/dns_records");
        curl_setopt_array($curl, [
            CURLOPT_HTTPHEADER     => [
                "X-Auth-Email: {$this->email}",
                "X-Auth-Key: {$this->key}",
            ],
            CURLOPT_RETURNTRANSFER => 1,
        ]);

        $result = @json_decode(curl_exec($curl), true);
        curl_close($curl);
        if (!isset($result['result'])) {
            throw new Exception('Cloudflare domain not found');
        }

        $records = [];
        foreach ($result['result'] as $dnsEntry) {
            /** @var array $dnsEntry */
            $name = rtrim($dnsEntry['name'], '.');
            $records["{$name}||{$dnsEntry['type']}||{$dnsEntry['content']}"] = [
                'name'          => $dnsEntry['name'],
                'ttl'           => $dnsEntry['ttl'],
                'type'          => $dnsEntry['type'],
                'content'       => $dnsEntry['content'],
                'cloudflare_id' => $dnsEntry['id'],
            ];
        }

        return $records;
    }

    /**
     * Get domain info
     *
     * @param string  $domainName Plesk ID = Domain name
     * @param boolean $refresh    When enabled, it will grab the latest info from Plesk
     *                            instead of the cached info.
     *
     * @return array
     *
     * @throws pm_Exception
     */
    private static function getDnsEntries($domainName, $refresh = false)
    {
        $pleskDomain = pm_Domain::getByName($domainName);
        if (!$refresh) {
            try {
                $db = pm_Bootstrap::getDbAdapter();
                $localRecords = $db->fetchRow($db->select()->from('cloudflaredns_domains')->where('domain = ?', $domainName));
                if (!$localRecords) {
                    $localRecords = ['dns' => ''];
                }
                $localRecords = isset($localRecords['dns']) ? $localRecords['dns'] : '';
                $localRecords = (array) @json_decode($localRecords, true);
            } catch (Exception $e) {
                $localRecords = [];
            }
            $records = [];
            foreach ($localRecords as $localRecord) {
                // Skip invalid entries
                if (!isset($localRecord['name']) || !isset($localRecord['type']) || !isset($localRecord['content'])) {
                    continue;
                }

                $records["{$localRecord['name']}||{$localRecord['type']}||{$localRecord['content']}"] = [
                    'name'    => $localRecord['name'],
                    'ttl'     => Modules_Cloudflaredns_Form_Settings::getTtl($pleskDomain->getId()),
                    'type'    => $localRecord['type'],
                    'content' => $localRecord['content'],
                ];
            }

            return $records;
        }

        $domain = $pleskDomain->getName();
        $request = <<<APICALL
<packet>
<dns>
 <get_rec>
  <filter>
   <site-id>{$pleskDomain->getId()}</site-id>
  </filter>
 </get_rec>
</dns>
</packet>
APICALL;
        $records = [];
        $response = pm_ApiRpc::getService(static::SERVICE_VERSION)->call($request);
        if (isset($response->dns->get_rec->result)) {
            foreach (json_decode(json_encode($response->dns->get_rec), true)['result'] as $localRecord) {
                $name = static::formatNameForCloudflare($domain, $localRecord['data']['host']);
                $type = $localRecord['data']['type'];
                $content = static::formatContentForCloudflare($domain, $localRecord['data']['value']);

                if (($type === 'MX' && $name === '@') || $type === 'NS') {
                    continue;
                }

                $records["{$name}||{$type}||{$content}"] = [
                    'name'    => $name,
                    'ttl'     => Modules_Cloudflaredns_Form_Settings::getTtl($pleskDomain->getId()),
                    'type'    => $type,
                    'content' => $content,
                ];
            }
        }

        return $records;
    }

    /**
     * Save domain info, in order to track changes
     *
     * @param string $domainName Plesk ID of domain
     * @param array  $info       DNS Entries
     *
     * @throws Zend_Db_Adapter_Exception
     * @throws Zend_Db_Statement_Exception
     */
    private function setDomainInfo($domainName, $info)
    {
        $db = pm_Bootstrap::getDbAdapter();
        $db->delete('cloudflaredns_domains', "`domain` = {$db->quote($domainName)}");
        $db->insert('cloudflaredns_domains', [
            'domain' => $domainName,
            'dns'    => json_encode($info),
        ]);
    }

    /**
     * @param string $domain
     * @param string $name
     *
     * @return string
     */
    public static function formatNameForCloudflare($domain, $name)
    {
        return $name;
    }

    /**
     * @param string $domain
     * @param string $content
     *
     * @return string
     */
    public static function formatContentForCloudflare($domain, $content)
    {
        return $content;
    }

    /**
     * @return array
     * @throws Exception
     */
    private function getZones()
    {
        if (!static::$zones) {
            $this->getDomainNames();
        }

        return static::$zones;
    }
}
