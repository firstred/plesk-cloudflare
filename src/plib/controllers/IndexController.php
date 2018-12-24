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
 * @author Michael Dekker <info@trendweb.io>
 * @license MIT
 */

/**
 * Class IndexController
 */
class IndexController extends pm_Controller_Action
{
    public function init()
    {
        parent::init();

        if (!pm_Session::getClient()->isAdmin()) {
            throw new pm_Exception('Permission denied');
        }
        /** @noinspection PhpUndefinedFieldInspection */
        $this->view->pageTitle = $this->lmsg('pageTitle');
    }

    /**
     * Index page
     */
    public function indexAction()
    {
        $form = new Modules_Cloudflaredns_Form_Settings();

        if ($this->getRequest()->isPost() && $form->isValid($this->getRequest()->getPost())) {
            try {
                $form->process();
                $this->_status->addInfo($this->lmsg('authDataSaved'));
            } catch (pm_Exception $e) {
                $this->_status->addError($e->getMessage());
            }
            $this->_helper->json(array('redirect' => pm_Context::getBaseUrl()));
        }

        $this->view->form = $form;
        $this->view->tabs = $this->_getTabs();
    }

    private function _getTabs()
    {
        $tabs = [];
        $tabs[] = [
            'title' => $this->lmsg('indexPageTitle'),
            'action' => 'index',
        ];
        if (pm_Settings::get(Modules_Cloudflaredns_Form_Settings::USERNAME)
            && pm_Settings::get(Modules_Cloudflaredns_Form_Settings::API_KEY)
        ) {
            $tabs[] = [
                'title' => $this->lmsg('domains'),
                'action' => 'domains',
            ];
        }
        return $tabs;
    }

    /**
     * Show domains
     *
     * @throws Exception
     */
    public function domainsAction()
    {
        $this->view->domainList = new Modules_Cloudflaredns_List_Domains($this->view, $this->getRequest(), $this->_helper);
        $this->view->tabs = $this->_getTabs();
    }

    /**
     * Enable multiple domains
     *
     * @throws Exception
     */
    public function enableDomainsAction()
    {
        $messages = [];
        $savedDomains = @json_decode(pm_Settings::get(Modules_Cloudflaredns_List_Domains::DOMAINS), true);
        $cloudflareDomains = Modules_Cloudflaredns_Client::getInstance()->getDomainNames();
        if (!is_array($savedDomains)) {
            $savedDomains = [];
        }
        foreach((array) $this->_getParam('ids') as $id) {
            if (!in_array($id, $cloudflareDomains)) {
                continue;
            }
            $savedDomains[] = $id;
            $messages[] = ['status' => 'info', 'content' => "Domain #$id has been enabled."];
        }
        $savedDomains = self::domainCleanup($savedDomains);
        pm_settings::set(Modules_Cloudflaredns_List_Domains::DOMAINS, json_encode($savedDomains));
        $this->_helper->json(['status' => 'success', 'statusMessages' => $messages]);
    }

    /**
     * Disable multiple domains
     *
     * @throws Exception
     */
    public function disableDomainsAction()
    {
        $messages = [];
        $savedDomains = @json_decode(pm_Settings::get(Modules_Cloudflaredns_List_Domains::DOMAINS), true);
        $cloudflareDomains = Modules_Cloudflaredns_Client::getInstance()->getDomainNames();
        if (!is_array($savedDomains)) {
            $savedDomains = [];
        }
        foreach((array) $this->_getParam('ids') as $id) {
            if (!in_array($id, $cloudflareDomains)) {
                continue;
            }
            $savedDomains = array_filter($savedDomains, function ($domain) use ($id) {
                return $domain !== $id;
            });
            $messages[] = ['status' => 'info', 'content' => "Domain #$id has been disabled."];
        }
        $savedDomains = self::domainCleanup($savedDomains);
        pm_settings::set(Modules_Cloudflaredns_List_Domains::DOMAINS, json_encode($savedDomains));
        $this->_helper->json(['status' => 'success', 'statusMessages' => $messages]);
    }

    /**
     * Enable a single domain
     *
     * @throws Exception
     */
    public function enableDomainAction()
    {
        if (!$this->getRequest()->isPost()) {
            throw new pm_Exception('Permission denied');
        }

        $savedDomains = @json_decode(pm_Settings::get(Modules_Cloudflaredns_List_Domains::DOMAINS), true);
        if (!is_array($savedDomains)) {
            $savedDomains = [];
        }
        $savedDomains[] = $this->_getParam('id');
        $savedDomains = self::domainCleanup($savedDomains);
        try {
            pm_Settings::set(Modules_Cloudflaredns_List_Domains::DOMAINS, json_encode($savedDomains));
            $this->_status->addMessage('info', $this->lmsg('domainEnabled'));
        } catch (Exception $e) {
            $this->_status->addMessage('error', $e->getMessage());
        }
        $this->_redirect('index/domains');
    }

    /**
     * Disable a single domain
     *
     * @throws Exception
     */
    public function disableDomainAction()
    {
        if (!$this->getRequest()->isPost()) {
            throw new pm_Exception('Permission denied');
        }

        $savedDomains = @json_decode(pm_Settings::get(Modules_Cloudflaredns_List_Domains::DOMAINS), true);
        if (!is_array($savedDomains)) {
            $savedDomains = [];
        }
        $savedDomains = array_filter($savedDomains, function ($domain) {
            return $domain !== $this->_getParam('id');
        });
        $savedDomains = self::domainCleanup($savedDomains);
        try {
            pm_Settings::set(Modules_Cloudflaredns_List_Domains::DOMAINS, json_encode($savedDomains));
            $this->_status->addMessage('info', $this->lmsg('domainDisabled'));
        } catch (Exception $e) {
            $this->_status->addMessage('error', $e->getMessage());
        }
        $this->_redirect('index/domains');
    }

    /**
     * Get the domains via ajax
     *
     * @throws Exception
     */
    public function domainsDataAction()
    {
        $list = new Modules_Cloudflaredns_List_Domains($this->view, $this->getRequest(), $this->_helper);
        $this->_helper->json($list->fetchData());
    }

    /**
     * Sync the selected domains
     */
    public function syncDomainsAction()
    {
        if (!$this->getRequest()->isPost()) {
            throw new pm_Exception('Permission denied');
        }

        Modules_Cloudflaredns_Client::getInstance()->syncDomains((array) $this->_getParam('ids'));
        $this->_helper->json(['status' => 'success', 'statusMessages' => [['status' => 'info', 'content' => $this->lmsg('domainsProcessed')]]]);
    }

    /**
     * Clean the domain list
     *
     * @param array $savedDomains
     *
     * @return array
     * @throws Exception
     */
    private static function domainCleanup($savedDomains)
    {
        try {
            $cloudflareDomains = Modules_Cloudflaredns_Client::getInstance()->getDomainNames();
        } catch (Exception $e) {
            throw $e;
        }

        return array_unique(array_filter($savedDomains, function ($domain) use ($cloudflareDomains) {
            return in_array($domain, $cloudflareDomains);
        }));
    }
}
