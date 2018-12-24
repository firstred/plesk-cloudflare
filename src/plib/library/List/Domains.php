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
 * Class Modules_Cloudflaredns_List_Domains
 */
class Modules_Cloudflaredns_List_Domains extends pm_View_List_Simple
{
    const DOMAINS = 'domains';

    private $helper;

    public function __construct($view, $request, $helper, $options = [])
    {
        $this->helper = $helper;
        parent::__construct($view, $request, $options);

        $this->setColumns([
            pm_View_List_Simple::COLUMN_SELECTION,
            'domain'  => [
                'title'    => $this->lmsg('domainsColumn'),
                'noEscape' => true,
            ],
            'enabled' => [
                'title'    => $this->lmsg('autoSync'),
                'noEscape' => true,
            ],
            'actions' => [
                'title'    => $this->lmsg('actionsColumn'),
                'noEscape' => true,
            ],
        ]);

        $this->setData($this->_getRecords($view));
        $this->setDataUrl($view->url(['action' => 'domains-data']));

        $this->setTools([
            [
                'title'              => $this->lmsg('enableDomainsButton'),
                'description'        => $this->lmsg('enableDomainsHint'),
                'class'              => 'sb-switch-service',
                'execGroupOperation' => $this->helper->url('enable-domains'),
            ],
            [
                'title'              => $this->lmsg('disableDomainsButton'),
                'description'        => $this->lmsg('disableDomainsHint'),
                'class'              => 'sb-disable',
                'execGroupOperation' => $this->helper->url('disable-domains'),
            ],
            [
                'title'              => $this->lmsg('syncDomainsButton'),
                'description'        => $this->lmsg('syncDomainsHint'),
                'class'              => 'sb-refresh',
                'execGroupOperation' => $this->helper->url('sync-domains'),
            ],
        ]);
    }

    private function _getRecords($view)
    {
        try {
            $cloudflareDomains = Modules_Cloudflaredns_Client::getInstance()->getDomainNames();
        } catch (Exception $e) {
            throw $e;
        }

        $pleskDomains = pm_Domain::getAllDomains(true);
        $savedDomains = @json_decode(pm_Settings::get(static::DOMAINS), true);
        if (!is_array($savedDomains)) {
            $savedDomains = [];
        }

        $listDomains = [];
        foreach ($pleskDomains as $domain) {
            $savedDomain = in_array($domain->getName(), $savedDomains);
            $cloudflareDomain = in_array($domain->getName(), $cloudflareDomains);

            $listDomains[$domain->getName()] = [
                'name'       => $domain->getName(),
                'enabled'    => $savedDomain && $cloudflareDomain,
                'cloudflare' => $cloudflareDomain,
            ];
        }

        $data = [];
        foreach ($listDomains as $id => $domain) {
            $urlId = urlencode($id);
            $data[$id] = [
                'domain'  => $domain['name'],
                'enabled' => $domain['enabled']
                    ? "<img src=\"/theme-skins/heavy-metal/icons/16/plesk/on.png?1537850981\" alt=\"enabled\" title=\"\"> ".$this->lmsg('on')
                    : "<img src=\"/theme-skins/heavy-metal/icons/16/plesk/off.png?1537850981\" alt=\"disabled\" title=\"\"> ".$this->lmsg('off'),
                'actions' => $domain['cloudflare'] ? ($domain['enabled']
                    ? "<a class='s-btn sb-disable' data-method='post'".
                    " href='{$view->url(['action' => 'disable-domain'])}?id=$urlId'>".
                    "<span>".$this->lmsg('disableAutoSync')."</span>".
                    "</a>"
                    : "<a class='s-btn sb-enable' data-method='post'".
                    " href='{$view->url(['action' => 'enable-domain'])}?id=$urlId'>".
                    "<span>".$this->lmsg('enableAutoSync')."</span>".
                    "</a>") : "<span>This domain is not in Cloudflare</span>",
            ];
        }

        return $data;
    }
}
