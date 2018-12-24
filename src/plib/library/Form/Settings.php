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
 * Class Modules_Cloudflaredns_Form_Settings
 */
class Modules_Cloudflaredns_Form_Settings extends pm_Form_Simple
{
    const USERNAME = 'cloudflare_email';
    const API_KEY = 'cloudflare_api_key';
    const OVERRIDE_TTL = 'cloudflare_override_ttl';
    const NEW_DOMAINS = 'cloudflare_new_domains';
    const PROXIED = 'cloudflare_proxied';

    private $isConsole = false;

    /**
     * Modules_Cloudflaredns_Form_Settings constructor.
     *
     * @param array $options
     */
    public function __construct($options = [])
    {
        if (!empty($options['isConsole'])) {
            $this->isConsole = $options['isConsole'];
        }

        parent::__construct($options);
    }

    /**
     * Init
     */
    public function init()
    {
        parent::init();

        $this->addElement('text', static::USERNAME, [
            'label'       => pm_Locale::lmsg('emailLabel'),
            'value'       => pm_Settings::get(static::USERNAME),
            'class'       => 'f-large-size',
            'required'    => true,
            'placeholder' => 'cloudflareuser@example.com',
            'validators'  => [
                ['NotEmpty', true],
            ],
        ]);
        $this->addElement('password', static::API_KEY, [
            'label'       => pm_Locale::lmsg('privateKeyLabel'),
            'value'       => pm_Settings::get(static::API_KEY),
            'required'    => false,
            'validators'  => [],
        ]);
        $this->addElement('text', static::OVERRIDE_TTL, [
            'label'       => pm_Locale::lmsg('overrideTtlLabel'),
            'description' => pm_Locale::lmsg('overrideTtlHint'),
            'value'       => pm_Settings::get(static::OVERRIDE_TTL),
            'required'    => false,
            'validators'  => [],
        ]);
        $this->addElement('checkbox', static::NEW_DOMAINS, [
            'label'      => pm_Locale::lmsg('syncNewDomainsLabel'),
            'value'      => pm_Settings::get(static::NEW_DOMAINS),
            'required'   => false,
            'validators' => [],
        ]);
        $this->addElement('checkbox', static::PROXIED, [
            'label'      => pm_Locale::lmsg('proxyByDefaultLabel'),
            'value'      => pm_Settings::get(static::PROXIED),
            'required'   => false,
            'validators' => [],
        ]);
        $this->addControlButtons([
            'cancelLink' => pm_Context::getModulesListUrl(),
        ]);
    }

    /**
     * @param array $data
     *
     * @return bool
     */
    public function isValid($data)
    {
        if (!parent::isValid($data)) {
            $this->markAsError();
            $this->getElement(static::USERNAME)->addError(pm_Locale::lmsg('emailPrivateKeyInvalidError'));
            $this->getElement(static::API_KEY)->addError(pm_Locale::lmsg('emailPrivateKeyInvalidError'));

            return false;
        }

        return true;
    }

    /**
     * @return array
     *
     * @throws Zend_Db_Table_Exception
     * @throws Zend_Db_Table_Row_Exception
     * @throws pm_Exception_InvalidArgumentException
     */
    public function process()
    {
        $res = [];

        $email = $this->getValue(static::USERNAME);
        $privateKey = $this->getValue(static::API_KEY);
        pm_Settings::set(static::OVERRIDE_TTL, $this->getValue(static::OVERRIDE_TTL));
        pm_Settings::set(static::NEW_DOMAINS, $this->getValue(static::NEW_DOMAINS));
        pm_Settings::set(static::PROXIED, $this->getValue(static::PROXIED));

        $this->saveUserData($email, $privateKey);

        return $res;
    }

    /**
     * @param string $email
     * @param string $privateKey
     *
     * @throws Zend_Db_Table_Exception
     * @throws Zend_Db_Table_Row_Exception
     * @throws pm_Exception_InvalidArgumentException
     */
    private function saveUserData($email, $privateKey)
    {
        pm_Settings::set(static::USERNAME, $email);
        if ($privateKey) {
            pm_Settings::set(static::API_KEY, $privateKey);
        }

    }

    /**
     * Get override TTL
     *
     * string $id Site ID
     *
     * @param string $id Site ID
     *
     * @return int TTL
     *
     * @throws pm_Exception
     */
    public static function getTtl($id)
    {
        static $saved = [];
        if (isset($saved[$id])) {
            return $saved[$id];
        }

        $savedTtl = pm_Settings::get(static::OVERRIDE_TTL);
        if (!$savedTtl) {
            $request = <<<APICALL
<packet>
<dns>
 <get>
  <filter>
   <site-id>{$id}</site-id>
  </filter>
  <soa/>
 </get>
</dns>
</packet>
APICALL;
            $response = pm_ApiRpc::getService(Modules_Cloudflaredns_Client::SERVICE_VERSION)->call($request);
            $savedTtl = isset($response->dns->get->result->soa->ttl) ? (int) $response->dns->get->result->soa->ttl : 300;
        }

        $saved[$id] = $savedTtl;

        return $savedTtl;
    }
}
