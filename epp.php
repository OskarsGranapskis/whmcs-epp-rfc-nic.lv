<?php
/**
 * Indera EPP registrar module for WHMCS (https://www.whmcs.com/)
 *
 * Written in 2024 by Taras Kondratyuk (https://getpinga.com)
 * Based on Generic EPP with DNSsec Registrar Module for WHMCS written in 2019 by Lilian Rudenco (info@xpanel.com)
 * Work of Lilian Rudenco is under http://opensource.org/licenses/afl-3.0.php Academic Free License (AFL 3.0)
 *
 * @license MIT
 */
if (!defined("WHMCS")) {
    die();
}

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

function epp_MetaData()
{
    return array(
        'DisplayName' => 'EPP Registry',
        'APIVersion' => '1.0.1',
    );
}

function _epp_error_handler($errno, $errstr, $errfile, $errline)
{
    if (!preg_match("/epp/i", $errfile)) {
        return true;
    }

    _epp_log("Error $errno:", "$errstr on line $errline in file $errfile");
}

set_error_handler('_epp_error_handler');
_epp_log('================= ' . date("Y-m-d H:i:s") . ' =================');

function epp_getConfigArray($params = array())
{
    _epp_log(__FUNCTION__, $params);
    _epp_create_table();
    _epp_create_column();

    $configarray = array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'EPP',
        ),
        'Description' => array(
            'Type' => 'System',
            'Value' => 'This module can be used with any registry that supports <a href="https://tools.ietf.org/html/rfc5731">RFC 5731</a>, <a href="https://tools.ietf.org/html/rfc5732">5732</a>, <a href="https://tools.ietf.org/html/rfc5733">5733</a>, <a href="https://tools.ietf.org/html/rfc5734">5734</a>',
        ),
        'host' => array(
            'FriendlyName' => 'EPP Server',
            'Type' => 'text',
            'Size' => '32',
            'Description' => 'EPP Server Host.'
        ),
        'port' => array(
            'FriendlyName' => 'Server Port',
            'Type' => 'text',
            'Size' => '4',
            'Default' => '700',
            'Description' => 'System port number 700 has been assigned by the IANA for mapping EPP onto TCP.'
        ),
        'tls_version' => array(
            'FriendlyName' => 'Use TLS v1.3',
            'Type' => 'yesno',
            'Description' => 'Use more secure TLS v1.3 if the registry supports it.'
        ),
        'verify_peer' => array(
            'FriendlyName' => 'Verify Peer',
            'Type' => 'yesno',
            'Description' => 'Require verification of SSL certificate used.'
        ),
        'cafile' => array(
            'FriendlyName' => 'CA File',
            'Type' => 'text',
            'Default' => '',
            'Description' => 'Certificate Authority file which should be used with the verify_peer context option <br />to authenticate the identity of the remote peer.'
        ),
        'local_cert' => array(
            'FriendlyName' => 'Certificate',
            'Type' => 'text',
            'Default' => 'cert.pem',
            'Description' => 'Local certificate file. It must be a PEM encoded file.'
        ),
        'local_pk' => array(
            'FriendlyName' => 'Private Key',
            'Type' => 'text',
            'Default' => 'key.pem',
            'Description' => 'Private Key.'
        ),
        'passphrase' => array(
            'FriendlyName' => 'Pass Phrase',
            'Type' => 'password',
            'Size' => '32',
            'Description' => 'Enter pass phrase with which your certificate file was encoded.'
        ),
        'clid' => array(
            'FriendlyName' => 'Client ID',
            'Type' => 'text',
            'Size' => '20',
            'Description' => 'Client identifier.'
        ),
        'pw' => array(
            'FriendlyName' => 'Password',
            'Type' => 'password',
            'Size' => '20',
            'Description' => "Client's plain text password."
        ),
        'registrarprefix' => array(
            'FriendlyName' => 'Registrar Prefix',
            'Type' => 'text',
            'Size' => '4',
            'Description' => 'Registry assigns each registrar a unique prefix with which that registrar must create contact IDs.'
        )
    );
    return $configarray;
}

function _epp_startEppClient($params = array())
{
    $s = new epp_epp_client($params);
    $s->login($params['clid'], $params['pw'], $params['registrarprefix']);
    return $s;
}

function epp_RegisterDomain($params = array())
{
    _epp_log(__FUNCTION__, $params);
    $return = array();
    try {
        $s = _epp_startEppClient($params);
        $from = $to = array();
        $from[] = '/{{ name }}/';
        $to[] = htmlspecialchars($params['domainname']);
        $from[] = '/{{ clTRID }}/';
        $clTRID = str_replace('.', '', round(microtime(1), 3));
        $to[] = htmlspecialchars($params['registrarprefix'] . '-domain-check-' . $clTRID);
        $xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <check>
      <domain:check
        xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
        xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
        <domain:name>{{ name }}</domain:name>
      </domain:check>
    </check>
    <clTRID>{{ clTRID }}</clTRID>
  </command>
</epp>');
        $r = $s->write($xml, __FUNCTION__);
        $r = $r->response->resData->children('urn:ietf:params:xml:ns:domain-1.0')->chkData;
        $reason = (string)$r->cd[0]->reason;
        if (!$reason) {
            $reason = 'Domain is not available';
        }

        if (0 == (int)$r->cd[0]->name->attributes()->avail) {
            throw new exception($r->cd[0]->name . ' ' . $reason);
        }

        $isCompany = !empty($params['companyname']);
        $neededContacts = $isCompany ? ['registrant', 'admin'] : ['registrant'];
        $regNr = htmlspecialchars($isCompany ? $params['customfields1'] : $params['customfields2']);
        $companyName = htmlspecialchars($params['companyname']);
        $vatNr = htmlspecialchars($params['tax_id']);

        $contacts = array();
        foreach($neededContacts as $i => $contactType) {
            $from = $to = array();
            $from[] = '/{{ id }}/';
            $to[] = strtoupper($s->generateRandomString());
            $from[] = '/{{ name }}/';
            $to[] = htmlspecialchars($params['firstname'] . ' ' . $params['lastname']);
            $from[] = '/{{ org }}/';
            $to[] = $contactType === 'admin' ? '' : $companyName;
            $from[] = '/{{ street1 }}/';
            $to[] = htmlspecialchars($params['address1']);
            $from[] = '/{{ street2 }}/';
            $to[] = htmlspecialchars($params['address2']);
            $from[] = '/{{ street3 }}/';
            $street3 = (isset($params['address3']) ? $params['address3'] : '');
            $to[] = htmlspecialchars($street3);
            $from[] = '/{{ city }}/';
            $to[] = htmlspecialchars($params['city']);
            $from[] = '/{{ state }}/';
            $to[] = htmlspecialchars($params['state']);
            $from[] = '/{{ postcode }}/';
            $to[] = htmlspecialchars($params['postcode']);
            $from[] = '/{{ country }}/';
            $to[] = htmlspecialchars($params['country']);
            $from[] = '/{{ phonenumber }}/';
            $to[] = htmlspecialchars($params['fullphonenumber']);
            $from[] = '/{{ email }}/';
            $to[] = htmlspecialchars($params['email']);
            $from[] = '/{{ authInfo }}/';
            $to[] = htmlspecialchars($s->generateObjectPW());
            $from[] = '/{{ regNr }}/';
            $to[] = $contactType === 'admin' ? '' : $regNr;
            $from[] = '/{{ vatNr }}/';
            $to[] = $contactType === 'admin' ? '' : $vatNr;
            $from[] = '/{{ clTRID }}/';
            $clTRID = str_replace('.', '', round(microtime(1), 3));
            $to[] = htmlspecialchars($params['registrarprefix'] . '-contact-create-' . $clTRID);
            $from[] = "/<\w+:\w+>\s*<\/\w+:\w+>\s+/ims";
            $to[] = '';
            $xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <create>
      <contact:create
       xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
        <contact:id>{{ id }}</contact:id>
        <contact:postalInfo type="loc">
          <contact:name>{{ name }}</contact:name>
          <contact:org>{{ org }}</contact:org>
          <contact:addr>
            <contact:street>{{ street1 }}</contact:street>
            <contact:street>{{ street2 }}</contact:street>
            <contact:street>{{ street3 }}</contact:street>
            <contact:city>{{ city }}</contact:city>
            <contact:sp>{{ state }}</contact:sp>
            <contact:pc>{{ postcode }}</contact:pc>
            <contact:cc>{{ country }}</contact:cc>
          </contact:addr>
        </contact:postalInfo>
        <contact:voice>{{ phonenumber }}</contact:voice>
        <contact:fax></contact:fax>
        <contact:email>{{ email }}</contact:email>
        <contact:authInfo>
          <contact:pw>{{ authInfo }}</contact:pw>
        </contact:authInfo>
      </contact:create>
    </create>

    <extension>
      <lvcontact:create xmlns:lvcontact="http://www.nic.lv/epp/schema/lvcontact-ext-1.0">
        <lvcontact:regNr>{{ regNr }}</lvcontact:regNr>
        <lvcontact:vatNr>{{ vatNr }}</lvcontact:vatNr>
      </lvcontact:create>
    </extension>
    <clTRID>{{ clTRID }}</clTRID>
  </command>
</epp>');
            $r = $s->write($xml, __FUNCTION__);
            $r = $r->response->resData->children('urn:ietf:params:xml:ns:contact-1.0')->creData;
            $contacts[$i + 1] = $r->id;
        }

    $from = $to = array();
    $from[] = '/{{ name }}/';
    $to[] = htmlspecialchars($params['domainname']);
    $from[] = '/{{ period }}/';
    $to[] = htmlspecialchars($params['regperiod']);

    // Dynamically generate the domain:ns block with hostAttr only for non-empty nameservers
    $nameservers = ['ns1', 'ns2', 'ns3', 'ns4', 'ns5'];
    $nsBlock = '';
    foreach ($nameservers as $nsKey) {
        if (!empty($params[$nsKey])) {
            $nsBlock .= '
              <domain:hostAttr>
                <domain:hostName>' . htmlspecialchars($params[$nsKey]) . '</domain:hostName>
              </domain:hostAttr>';
        }
    }
    $from[] = '/{{ nsBlock }}/';
    $to[] = $nsBlock;

    $from[] = '/{{ cID_1 }}/';
    $to[] = htmlspecialchars($contacts[1]);
    $from[] = '/{{ cID_2 }}/';
    $to[] = htmlspecialchars($contacts[$isCompany ? 2 : 1]);
    $from[] = '/{{ cID_3 }}/';
    $to[] = '__DEFAULT__';
    $from[] = '/{{ cID_4 }}/';
    $to[] = '__DEFAULT__';
    $from[] = '/{{ authInfo }}/';
    $to[] = htmlspecialchars($s->generateObjectPW());
    $from[] = '/{{ clTRID }}/';
    $clTRID = str_replace('.', '', round(microtime(1), 3));
    $to[] = htmlspecialchars($params['registrarprefix'] . '-domain-create-' . $clTRID);

    $from[] = "/<\w+:\w+>\s*<\/\w+:\w+>\s+/ims";
    $to[] = '';

    $xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
    <epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
      xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
      xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
      <command>
        <create>
          <domain:create
          xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
            <domain:name>{{ name }}</domain:name>
            <domain:period unit="y">{{ period }}</domain:period>
            <domain:ns>
              {{ nsBlock }}
            </domain:ns>
            <domain:registrant>{{ cID_1 }}</domain:registrant>
            <domain:contact type="admin">{{ cID_2 }}</domain:contact>
            <domain:contact type="tech">{{ cID_3 }}</domain:contact>
            <domain:contact type="billing">{{ cID_4 }}</domain:contact>
            <domain:authInfo>
              <domain:pw>{{ authInfo }}</domain:pw>
            </domain:authInfo>
          </domain:create>
        </create>
        <clTRID>{{ clTRID }}</clTRID>
      </command>
    </epp>');
        $r = $s->write($xml, __FUNCTION__);
    } catch(exception $e) {
        $return = array(
            'error' => $e->getMessage()
        );
    }

    if (!empty($s)) {
        $s->logout($params['registrarprefix']);
    }

    return $return;
}

function epp_RenewDomain($params = array())
{
    _epp_log(__FUNCTION__, $params);
    $return = array();
    try {
        $s = _epp_startEppClient($params);
        $from = $to = array();
        $from[] = '/{{ name }}/';
        $to[] = htmlspecialchars($params['domainname']);
        $from[] = '/{{ clTRID }}/';
        $clTRID = str_replace('.', '', round(microtime(1), 3));
        $to[] = htmlspecialchars($params['registrarprefix'] . '-domain-info-' . $clTRID);
        $xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <info>
      <domain:info
       xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
       xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
        <domain:name hosts="all">{{ name }}</domain:name>
      </domain:info>
    </info>
    <clTRID>{{ clTRID }}</clTRID>
  </command>
</epp>');
        $r = $s->write($xml, __FUNCTION__);
        $r = $r->response->resData->children('urn:ietf:params:xml:ns:domain-1.0')->infData;
        $expDate = (string)$r->exDate;
        $expDate = preg_replace("/^(\d+\-\d+\-\d+)\D.*$/", "$1", $expDate);
        $from = $to = array();
        $from[] = '/{{ name }}/';
        $to[] = htmlspecialchars($params['domainname']);
        $from[] = '/{{ regperiod }}/';
        $to[] = htmlspecialchars($params['regperiod']);
        $from[] = '/{{ expDate }}/';
        $to[] = htmlspecialchars($expDate);
        $from[] = '/{{ clTRID }}/';
        $clTRID = str_replace('.', '', round(microtime(1), 3));
        $to[] = htmlspecialchars($params['registrarprefix'] . '-domain-renew-' . $clTRID);
        $xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <renew>
      <domain:renew
       xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
        <domain:name>{{ name }}</domain:name>
        <domain:curExpDate>{{ expDate }}</domain:curExpDate>
        <domain:period unit="y">{{ regperiod }}</domain:period>
      </domain:renew>
    </renew>
    <clTRID>{{ clTRID }}</clTRID>
  </command>
</epp>');
        $r = $s->write($xml, __FUNCTION__);
    } catch(exception $e) {
        $return = array(
            'error' => $e->getMessage()
        );
    }

    if (!empty($s)) {
        $s->logout($params['registrarprefix']);
    }

    return $return;
}

function epp_TransferDomain($params = array())
{
    _epp_log(__FUNCTION__, $params);
    $return = array();
    try {
        $s = _epp_startEppClient($params);
        $from = $to = array();
        $from[] = '/{{ name }}/';
        $to[] = htmlspecialchars($params['domainname']);
        $from[] = '/{{ years }}/';
        $to[] = htmlspecialchars($params['regperiod']);
        $from[] = '/{{ authInfo_pw }}/';
        $to[] = $params['transfersecret'];
        $from[] = '/{{ clTRID }}/';
        $clTRID = str_replace('.', '', round(microtime(1), 3));
        $to[] = htmlspecialchars($params['registrarprefix'] . '-domain-transfer-' . $clTRID);
        $xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <transfer op="request">
      <domain:transfer
       xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
        <domain:name>{{ name }}</domain:name>
        <domain:authInfo>
          <domain:pw>{{ authInfo_pw }}</domain:pw>
        </domain:authInfo>
      </domain:transfer>
    </transfer>
    <clTRID>{{ clTRID }}</clTRID>
  </command>
</epp>');
        $r = $s->write($xml, __FUNCTION__);
    } catch(exception $e) {
        $return = array(
            'error' => $e->getMessage()
        );
    }

    if (!empty($s)) {
        $s->logout($params['registrarprefix']);
    }

    return $return;
}

function epp_GetNameservers($params = array())
{
    _epp_log(__FUNCTION__, $params);
    $return = array();
    try {
        $s = _epp_startEppClient($params);
        $from = $to = array();
        $from[] = '/{{ name }}/';
        $to[] = htmlspecialchars($params['domainname']);
        $from[] = '/{{ clTRID }}/';
        $clTRID = str_replace('.', '', round(microtime(1), 3));
        $to[] = htmlspecialchars($params['registrarprefix'] . '-domain-info-' . $clTRID);
        $xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <info>
      <domain:info
       xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
       xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
        <domain:name hosts="all">{{ name }}</domain:name>
      </domain:info>
    </info>
    <clTRID>{{ clTRID }}</clTRID>
  </command>
</epp>');
        $r = $s->write($xml, __FUNCTION__);
        $r = $r->response->resData->children('urn:ietf:params:xml:ns:domain-1.0')->infData;
        $i = 0;
        foreach($r->ns->hostObj as $ns) {
            $i++;
            $return["ns{$i}"] = (string)$ns;
        }

        $status = array();
        Capsule::table('epp_domain_status')->where('domain_id', '=', $params['domainid'])->delete();
        foreach($r->status as $e) {
            $st = (string)$e->attributes()->s;
            if ($st == 'pendingDelete') {
                $updatedDomainStatus = Capsule::table('tbldomains')->where('id', $params['domainid'])->update(['status' => 'Cancelled']);
            }

            Capsule::table('epp_domain_status')->insert(['domain_id' => $params['domainid'], 'status' => $st]);
        }
    } catch(exception $e) {
        $return = array(
            'error' => $e->getMessage()
        );
    }

    if (!empty($s)) {
        $s->logout($params['registrarprefix']);
    }

    return $return;
}

function epp_SaveNameservers($params = array())
{
    _epp_log(__FUNCTION__, $params);
    $return = array();
    try {
        $s = _epp_startEppClient($params);
        $from = $to = array();
        $from[] = '/{{ name }}/';
        $to[] = htmlspecialchars($params['domainname']);
        $from[] = '/{{ clTRID }}/';
        $clTRID = str_replace('.', '', round(microtime(1), 3));
        $to[] = htmlspecialchars($params['registrarprefix'] . '-domain-info-' . $clTRID);
        $xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <info>
      <domain:info
       xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
       xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
        <domain:name hosts="all">{{ name }}</domain:name>
      </domain:info>
    </info>
    <clTRID>{{ clTRID }}</clTRID>
  </command>
</epp>');
        $r = $s->write($xml, __FUNCTION__);
        $r = $r->response->resData->children('urn:ietf:params:xml:ns:domain-1.0')->infData;
        $add = $rem = array();
        $i = 0;
        foreach($r->ns->hostObj as $ns) {
            $i++;
            $ns = (string)$ns;
            if (!$ns) {
                continue;
            }

            $rem["ns{$i}"] = $ns;
        }

        foreach($params as $k => $v) {
            if (!$v) {
                continue;
            }

            if (!preg_match("/^ns\d$/i", $k)) {
                continue;
            }

            if ($k0 = array_search($v, $rem)) {
                unset($rem[$k0]);
            } else {
                $add[$k] = $v;
            }
        }

        if (!empty($add) || !empty($rem)) {
            $from = $to = array();
            $text = '';
            foreach($add as $k => $v) {
                $text.= '<domain:hostObj>' . $v . '</domain:hostObj>' . "\n";
            }

            $from[] = '/{{ add }}/';
            $to[] = (empty($text) ? '' : "<domain:add><domain:ns>\n{$text}</domain:ns></domain:add>\n");
            $text = '';
            foreach($rem as $k => $v) {
                $text.= '<domain:hostObj>' . $v . '</domain:hostObj>' . "\n";
            }

            $from[] = '/{{ rem }}/';
            $to[] = (empty($text) ? '' : "<domain:rem><domain:ns>\n{$text}</domain:ns></domain:rem>\n");
            $from[] = '/{{ name }}/';
            $to[] = htmlspecialchars($params['domainname']);
            $from[] = '/{{ clTRID }}/';
            $clTRID = str_replace('.', '', round(microtime(1), 3));
            $to[] = htmlspecialchars($params['registrarprefix'] . '-domain-update-' . $clTRID);
            $xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <update>
      <domain:update
       xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
       xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
        <domain:name>{{ name }}</domain:name>
    {{ add }}
    {{ rem }}
      </domain:update>
    </update>
    <clTRID>{{ clTRID }}</clTRID>
  </command>
</epp>');
            $r = $s->write($xml, __FUNCTION__);
        }
    } catch(exception $e) {
        $return = array(
            'error' => $e->getMessage()
        );
    }

    if (!empty($s)) {
        $s->logout($params['registrarprefix']);
    }

    return $return;
}

function epp_GetRegistrarLock($params = array())
{
    _epp_log(__FUNCTION__, $params);
    $return = 'unlocked';
    try {
        $s = _epp_startEppClient($params);
        $from = $to = array();
        $from[] = '/{{ name }}/';
        $to[] = htmlspecialchars($params['domainname']);
        $from[] = '/{{ clTRID }}/';
        $clTRID = str_replace('.', '', round(microtime(1), 3));
        $to[] = htmlspecialchars($params['registrarprefix'] . '-domain-info-' . $clTRID);
        $xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <info>
      <domain:info
       xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
       xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
        <domain:name hosts="all">{{ name }}</domain:name>
      </domain:info>
    </info>
    <clTRID>{{ clTRID }}</clTRID>
  </command>
</epp>');
        $r = $s->write($xml, __FUNCTION__);
        $r = $r->response->resData->children('urn:ietf:params:xml:ns:domain-1.0')->infData;
        foreach($r->status as $e) {
            $attr = $e->attributes();
            if (preg_match("/clientTransferProhibited/i", $attr['s'])) {
                $return = 'locked';
            }
        }
    } catch(exception $e) {
        $return = 'locked';
    }

    if (!empty($s)) {
        $s->logout($params['registrarprefix']);
    }

    return $return;
}

function epp_SaveRegistrarLock($params = array())
{
    _epp_log(__FUNCTION__, $params);
    $return = array();
    try {
        $s = _epp_startEppClient($params);
        $from = $to = array();
        $from[] = '/{{ name }}/';
        $to[] = htmlspecialchars($params['domainname']);
        $from[] = '/{{ clTRID }}/';
        $clTRID = str_replace('.', '', round(microtime(1), 3));
        $to[] = htmlspecialchars($params['registrarprefix'] . '-domain-info-' . $clTRID);
        $xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <info>
      <domain:info
       xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
       xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
        <domain:name hosts="all">{{ name }}</domain:name>
      </domain:info>
    </info>
    <clTRID>{{ clTRID }}</clTRID>
  </command>
</epp>');
        $r = $s->write($xml, __FUNCTION__);
        $r = $r->response->resData->children('urn:ietf:params:xml:ns:domain-1.0')->infData;
        $status = array();
        foreach($r->status as $e) {
            $st = (string)$e->attributes()->s;
            if (!preg_match("/^client.+Prohibited$/i", $st)) {
                continue;
            }

            $status[$st] = true;
        }

        $rem = $add = array();
        foreach(array(
            'clientDeleteProhibited',
            'clientTransferProhibited'
        ) as $st) {
            if ($params["lockenabled"] == 'locked') {
                if (!isset($status[$st])) {
                    $add[] = $st;
                }
            } else {
                if (isset($status[$st])) {
                    $rem[] = $st;
                }
            }
        }

        if (!empty($add) || !empty($rem)) {
            $text = '';
            foreach($add as $st) {
                $text.= '<domain:status s="' . $st . '" lang="en"></domain:status>' . "\n";
            }

            $from[] = '/{{ add }}/';
            $to[] = (empty($text) ? '' : "<domain:add>\n{$text}</domain:add>\n");
            $text = '';
            foreach($rem as $st) {
                $text.= '<domain:status s="' . $st . '" lang="en"></domain:status>' . "\n";
            }

            $from[] = '/{{ rem }}/';
            $to[] = (empty($text) ? '' : "<domain:rem>\n{$text}</domain:rem>\n");
            $from[] = '/{{ name }}/';
            $to[] = htmlspecialchars($params['domainname']);
            $from[] = '/{{ clTRID }}/';
            $clTRID = str_replace('.', '', round(microtime(1), 3));
            $to[] = htmlspecialchars($params['registrarprefix'] . '-domain-update-' . $clTRID);
            $xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <update>
      <domain:update
       xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
       xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
        <domain:name>{{ name }}</domain:name>
        {{ rem }}
        {{ add }}
      </domain:update>
    </update>
    <clTRID>{{ clTRID }}</clTRID>
  </command>
</epp>');
            $r = $s->write($xml, __FUNCTION__);
        }
    } catch(exception $e) {
        $return = array(
            'error' => $e->getMessage()
        );
    }

    if (!empty($s)) {
        $s->logout($params['registrarprefix']);
    }

    return $return;
}

function epp_GetContactDetails($params = array())
{
    _epp_log(__FUNCTION__, $params);
    $return = array();
    try {
        $s = _epp_startEppClient($params);
        $from = $to = array();
        $from[] = '/{{ name }}/';
        $to[] = htmlspecialchars($params['domainname']);
        $from[] = '/{{ clTRID }}/';
        $clTRID = str_replace('.', '', round(microtime(1), 3));
        $to[] = htmlspecialchars($params['registrarprefix'] . '-domain-info-' . $clTRID);
        $xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <info>
      <domain:info
       xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
       xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
        <domain:name hosts="all">{{ name }}</domain:name>
      </domain:info>
    </info>
    <clTRID>{{ clTRID }}</clTRID>
  </command>
</epp>');
        $r = $s->write($xml, __FUNCTION__);
        $r = $r->response->resData->children('urn:ietf:params:xml:ns:domain-1.0')->infData;
        $dcontact = array();
        $dcontact['registrant'] = (string)$r->registrant;
        foreach($r->contact as $e) {
            $type = (string)$e->attributes()->type;
            $dcontact[$type] = (string)$e;
        }

        $contact = array();
        foreach($dcontact as $id) {
            if (isset($contact[$id])) {
                continue;
            }

            $from = $to = array();
            $from[] = '/{{ id }}/';
            $to[] = htmlspecialchars($id);
            $from[] = '/{{ clTRID }}/';
            $clTRID = str_replace('.', '', round(microtime(1), 3));
            $to[] = htmlspecialchars($params['registrarprefix'] . '-contact-info-' . $clTRID);
            $xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <info>
      <contact:info
       xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
        <contact:id>{{ id }}</contact:id>
      </contact:info>
    </info>
    <clTRID>{{ clTRID }}</clTRID>
  </command>
</epp>');
            $r = $s->write($xml, __FUNCTION__);
            $r = $r->response->resData->children('urn:ietf:params:xml:ns:contact-1.0')->infData[0];
            $contact[$id] = array();
            $c = & $contact[$id];
            foreach($r->postalInfo as $e) {
                $c["Name"] = (string)$e->name;
                $c["Organization"] = (string)$e->org;
                for ($i = 0; $i <= 2; $i++) {
                    $c["Street " . ($i + 1) ] = (string)$e->addr->street[$i];
                }

                if (empty($c["Street 3"])) {
                    unset($c["street3"]);
                }

                $c["City"] = (string)$e->addr->city;
                $c["State or Province"] = (string)$e->addr->sp;
                $c["Postal Code"] = (string)$e->addr->pc;
                $c["Country Code"] = (string)$e->addr->cc;
                break;
            }

            $c["Phone"] = (string)$r->voice;
            $c["Fax"] = (string)$r->fax;
            $c["Email"] = (string)$r->email;
        }

        foreach($dcontact as $type => $id) {
            if ($type == 'registrant') {
                $type = 'Registrant';
            } elseif ($type == 'admin') {
                $type = 'Administrator';
            } elseif ($type == 'tech') {
                $type = 'Technical';
            } elseif ($type == 'billing') {
                $type = 'Billing';
            } else {
                continue;
            }

            $return[$type] = $contact[$id];
        }
    } catch(exception $e) {
        $return = array(
            'error' => $e->getMessage()
        );
    }

    if (!empty($s)) {
        $s->logout($params['registrarprefix']);
    }

    return $return;
}

function epp_SaveContactDetails($params = array())
{
    _epp_log(__FUNCTION__, $params);
    $return = array();
    try {
        $s = _epp_startEppClient($params);
        $from = $to = array();
        $from[] = '/{{ name }}/';
        $to[] = htmlspecialchars($params['domainname']);
        $from[] = '/{{ clTRID }}/';
        $clTRID = str_replace('.', '', round(microtime(1), 3));
        $to[] = htmlspecialchars($params['registrarprefix'] . '-domain-info-' . $clTRID);
        $xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <info>
      <domain:info
       xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
       xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
        <domain:name hosts="all">{{ name }}</domain:name>
      </domain:info>
    </info>
    <clTRID>{{ clTRID }}</clTRID>
  </command>
</epp>');
        $r = $s->write($xml, __FUNCTION__);
        $r = $r->response->resData->children('urn:ietf:params:xml:ns:domain-1.0')->infData;
        $dcontact = array();
        $dcontact['registrant'] = (string)$r->registrant;
        foreach($r->contact as $e) {
            $type = (string)$e->attributes()->type;
            $dcontact[$type] = (string)$e;
        }

        foreach($dcontact as $type => $id) {
            $a = array();
            if ($type == 'registrant') {
                $a = $params['contactdetails']['Registrant'];
            } elseif ($type == 'admin') {
                $a = $params['contactdetails']['Administrator'];
            } elseif ($type == 'tech') {
                $a = $params['contactdetails']['Technical'];
            } elseif ($type == 'billing') {
                $a = $params['contactdetails']['Billing'];
            }

            if (empty($a)) {
                continue;
            }

            $from = $to = array();

            $from[] = '/{{ id }}/';
            $to[] = htmlspecialchars($id);

            $from[] = '/{{ name }}/';
            $name = ($a['Name'] ? $a['Name'] : $a['Full Name']);
            $to[] = htmlspecialchars($name);

            $from[] = '/{{ org }}/';
            $org = ($a['Organization'] ? $a['Organization'] : $a['Organisation Name']);
            $to[] = htmlspecialchars($org);

            $from[] = '/{{ street1 }}/';
            $street1 = ($a['Street 1'] ? $a['Street 1'] : $a['Address 1']);
            $to[] = htmlspecialchars($street1);

            $from[] = '/{{ street2 }}/';
            $street2 = ($a['Street 2'] ? $a['Street 2'] : $a['Address 2']);
            $to[] = htmlspecialchars($street2);

            $from[] = '/{{ street3 }}/';
            $street3 = ($a['Street 3'] ? $a['Street 3'] : $a['Address 3']);
            $to[] = htmlspecialchars($street3);

            $from[] = '/{{ city }}/';
            $to[] = htmlspecialchars($a['City']);

            $from[] = '/{{ sp }}/';
            $sp = ($a['State or Province'] ? $a['State or Province'] : $a['State']);
            $to[] = htmlspecialchars($sp);

            $from[] = '/{{ pc }}/';
            $pc = ($a['Postal Code'] ? $a['Postal Code'] : $a['Postcode']);
            $to[] = htmlspecialchars($pc);

            $from[] = '/{{ cc }}/';
            $cc = ($a['Country Code'] ? $a['Country Code'] : $a['Country']);
            $to[] = htmlspecialchars($cc);

            $from[] = '/{{ voice }}/';
            $to[] = htmlspecialchars($a['Phone']);

            $from[] = '/{{ fax }}/';
            $to[] = htmlspecialchars($a['Fax']);

            $from[] = '/{{ email }}/';
            $to[] = htmlspecialchars($a['Email']);

            $from[] = '/{{ clTRID }}/';
            $clTRID = str_replace('.', '', round(microtime(1), 3));
            $to[] = htmlspecialchars($params['registrarprefix'] . '-contact-chg-' . $clTRID);
            $xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <update>
      <contact:update xmlns:contact="urn:ietf:params:xml:ns:contact-1.0" xsi:schemaLocation="urn:ietf:params:xml:ns:contact-1.0 contact-1.0.xsd">
        <contact:id>{{ id }}</contact:id>
        <contact:chg>
          <contact:postalInfo type="loc">
            <contact:name>{{ name }}</contact:name>
            <contact:org>{{ org }}</contact:org>
            <contact:addr>
              <contact:street>{{ street1 }}</contact:street>
              <contact:street>{{ street2 }}</contact:street>
              <contact:street>{{ street3 }}</contact:street>
              <contact:city>{{ city }}</contact:city>
              <contact:sp>{{ sp }}</contact:sp>
              <contact:pc>{{ pc }}</contact:pc>
              <contact:cc>{{ cc }}</contact:cc>
            </contact:addr>
          </contact:postalInfo>
          <contact:voice>{{ voice }}</contact:voice>
          <contact:fax>{{ fax }}</contact:fax>
          <contact:email>{{ email }}</contact:email>
        </contact:chg>
      </contact:update>
    </update>
    <clTRID>{{ clTRID }}</clTRID>
  </command>
</epp>');
            $r = $s->write($xml, __FUNCTION__);
        }
    } catch(exception $e) {
        $return = array(
            'error' => $e->getMessage()
        );
    }

    if (!empty($s)) {
        $s->logout($params['registrarprefix']);
    }

    return $return;
}

function epp_IDProtectToggle($params = array())
{
    _epp_log(__FUNCTION__, $params);
    $return = array();
    try {
        $s = _epp_startEppClient($params);
        $from = $to = array();
        $from[] = '/{{ name }}/';
        $to[] = htmlspecialchars($params['domainname']);
        $from[] = '/{{ clTRID }}/';
        $clTRID = str_replace('.', '', round(microtime(1), 3));
        $to[] = htmlspecialchars($params['registrarprefix'] . '-domain-info-' . $clTRID);
        $xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <info>
      <domain:info
       xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
       xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
        <domain:name hosts="all">{{ name }}</domain:name>
      </domain:info>
    </info>
    <clTRID>{{ clTRID }}</clTRID>
  </command>
</epp>');
        $r = $s->write($xml, __FUNCTION__);
        $r = $r->response->resData->children('urn:ietf:params:xml:ns:domain-1.0')->infData;
        $dcontact = array();
        $dcontact['registrant'] = (string)$r->registrant;
        foreach($r->contact as $e) {
            $type = (string)$e->attributes()->type;
            $dcontact[$type] = (string)$e;
        }

        $contact = array();
        foreach($dcontact as $id) {
            if (isset($contact[$id])) {
                continue;
            }

            $from = $to = array();
            $from[] = '/{{ id }}/';
            $to[] = htmlspecialchars($id);

            $from[] = '/{{ flag }}/';
            $to[] = ($params['protectenable'] ? 1 : 0);

            $from[] = '/{{ clTRID }}/';
            $clTRID = str_replace('.', '', round(microtime(1) , 3));
            $to[] = htmlspecialchars($params['RegistrarPrefix'] . '-contact-update-' . $clTRID);

            $xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
     xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
     xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <update>
      <contact:update xmlns:contact="urn:ietf:params:xml:ns:contact-1.0" xsi:schemaLocation="urn:ietf:params:xml:ns:contact-1.0 contact-1.0.xsd">
        <contact:id>{{ id }}</contact:id>
        <contact:chg>
          <contact:disclose flag="{{ flag }}">
            <contact:name type="int"/>
            <contact:addr type="int"/>
            <contact:voice/>
            <contact:fax/>
            <contact:email/>
          </contact:disclose>
        </contact:chg>
      </contact:update>
    </update>
    <clTRID>{{ clTRID }}</clTRID>
  </command>
</epp>');
            $r = $s->write($xml, __FUNCTION__);
        }
    } catch(exception $e) {
        $return = array(
            'error' => $e->getMessage()
        );
    }

    if (!empty($s)) {
        $s->logout($params['RegistrarPrefix']);
    }

    return $return;
}

function epp_GetEPPCode($params = array())
{
    _epp_log(__FUNCTION__, $params);
    $return = array();
    try {
        $s = _epp_startEppClient($params);
        $from = $to = array();
        $from[] = '/{{ name }}/';
        $to[] = htmlspecialchars($params['domainname']);
        $from[] = '/{{ clTRID }}/';
        $clTRID = str_replace('.', '', round(microtime(1), 3));
        $to[] = htmlspecialchars($params['registrarprefix'] . '-domain-info-' . $clTRID);
        $xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <info>
      <domain:info
       xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
       xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
        <domain:name hosts="all">{{ name }}</domain:name>
      </domain:info>
    </info>
    <clTRID>{{ clTRID }}</clTRID>
  </command>
</epp>');
        $r = $s->write($xml, __FUNCTION__);
        $r = $r->response->resData->children('urn:ietf:params:xml:ns:domain-1.0')->infData;
        $eppcode = (string)$r->authInfo->pw;

        // If EPP Code is returned, return it for display to the end user
        if (!empty($s)) {
            $s->logout($params['registrarprefix']);
        }
        return array('eppcode' => $eppcode);
    } catch(exception $e) {
        $return = array(
            'error' => $e->getMessage()
        );
    }

    if (!empty($s)) {
        $s->logout($params['registrarprefix']);
    }

    return $return;
}

function epp_RegisterNameserver($params = array())
{
    _epp_log(__FUNCTION__, $params);
    $return = array();
    try {
        $s = _epp_startEppClient($params);
        $from = $to = array();
        $from[] = '/{{ name }}/';
        $to[] = htmlspecialchars($params['nameserver']);
        $from[] = '/{{ clTRID }}/';
        $clTRID = str_replace('.', '', round(microtime(1), 3));
        $to[] = htmlspecialchars($params['registrarprefix'] . '-host-check-' . $clTRID);
        $xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <check>
      <host:check
       xmlns:host="urn:ietf:params:xml:ns:host-1.0">
        <host:name>{{ name }}</host:name>
      </host:check>
    </check>
    <clTRID>{{ clTRID }}</clTRID>
  </command>
</epp>');
        $r = $s->write($xml, __FUNCTION__);
        $r = $r->response->resData->children('urn:ietf:params:xml:ns:host-1.0')->chkData;
        if (0 == (int)$r->cd[0]->name->attributes()->avail) {
            throw new exception($r->cd[0]->name . " " . $r->cd[0]->reason);
        }

        $from = $to = array();
        $from[] = '/{{ name }}/';
        $to[] = htmlspecialchars($params['nameserver']);
        $from[] = '/{{ ip }}/';
        $to[] = htmlspecialchars($params['ipaddress']);
        $from[] = '/{{ v }}/';
        $to[] = (preg_match('/:/', $params['ipaddress']) ? 'v6' : 'v4');
        $from[] = '/{{ clTRID }}/';
        $clTRID = str_replace('.', '', round(microtime(1), 3));
        $to[] = htmlspecialchars($params['registrarprefix'] . '-host-create-' . $clTRID);
        $xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <create>
      <host:create
       xmlns:host="urn:ietf:params:xml:ns:host-1.0">
        <host:name>{{ name }}</host:name>
        <host:addr ip="{{ v }}">{{ ip }}</host:addr>
      </host:create>
    </create>
    <clTRID>{{ clTRID }}</clTRID>
  </command>
</epp>');
        $r = $s->write($xml, __FUNCTION__);
    } catch(exception $e) {
        $return = array(
            'error' => $e->getMessage()
        );
    }

    if (!empty($s)) {
        $s->logout($params['registrarprefix']);
    }

    return $return;
}

function epp_ModifyNameserver($params = array())
{
    _epp_log(__FUNCTION__, $params);
    $return = array();
    try {
        $s = _epp_startEppClient($params);
        $from = $to = array();
        $from[] = '/{{ name }}/';
        $to[] = htmlspecialchars($params['nameserver']);
        $from[] = '/{{ ip1 }}/';
        $to[] = htmlspecialchars($params['currentipaddress']);
        $from[] = '/{{ v1 }}/';
        $to[] = (preg_match('/:/', $params['currentipaddress']) ? 'v6' : 'v4');
        $from[] = '/{{ ip2 }}/';
        $to[] = htmlspecialchars($params['newipaddress']);
        $from[] = '/{{ v2 }}/';
        $to[] = (preg_match('/:/', $params['newipaddress']) ? 'v6' : 'v4');
        $from[] = '/{{ clTRID }}/';
        $clTRID = str_replace('.', '', round(microtime(1), 3));
        $to[] = htmlspecialchars($params['registrarprefix'] . '-host-update-' . $clTRID);
        $xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <update>
      <host:update
       xmlns:host="urn:ietf:params:xml:ns:host-1.0">
        <host:name>{{ name }}</host:name>
        <host:add>
          <host:addr ip="{{ v2 }}">{{ ip2 }}</host:addr>
        </host:add>
        <host:rem>
          <host:addr ip="{{ v1 }}">{{ ip1 }}</host:addr>
        </host:rem>
      </host:update>
    </update>
    <clTRID>{{ clTRID }}</clTRID>
  </command>
</epp>');
        $r = $s->write($xml, __FUNCTION__);
    } catch(exception $e) {
        $return = array(
            'error' => $e->getMessage()
        );
    }

    if (!empty($s)) {
        $s->logout($params['registrarprefix']);
    }

    return $return;
}

function epp_DeleteNameserver($params = array())
{
    _epp_log(__FUNCTION__, $params);
    $return = array();
    try {
        $s = _epp_startEppClient($params);
        $from = $to = array();
        $from[] = '/{{ name }}/';
        $to[] = htmlspecialchars($params['nameserver']);
        $from[] = '/{{ clTRID }}/';
        $clTRID = str_replace('.', '', round(microtime(1), 3));
        $to[] = htmlspecialchars($params['registrarprefix'] . '-host-delete-' . $clTRID);
        $xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <delete>
      <host:delete
       xmlns:host="urn:ietf:params:xml:ns:host-1.0">
        <host:name>{{ name }}</host:name>
      </host:delete>
    </delete>
    <clTRID>{{ clTRID }}</clTRID>
  </command>
</epp>');
        $r = $s->write($xml, __FUNCTION__);
    } catch(exception $e) {
        $return = array(
            'error' => $e->getMessage()
        );
    }

    if (!empty($s)) {
        $s->logout($params['registrarprefix']);
    }

    return $return;
}

function epp_RequestDelete($params = array())
{
    _epp_log(__FUNCTION__, $params);
    $return = array();
    try {
        $s = _epp_startEppClient($params);
        $from = $to = array();
        $from[] = '/{{ name }}/';
        $to[] = htmlspecialchars($params['domainname']);
        $from[] = '/{{ clTRID }}/';
        $clTRID = str_replace('.', '', round(microtime(1), 3));
        $to[] = htmlspecialchars($params['registrarprefix'] . '-domain-delete-' . $clTRID);
        $xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <delete>
      <domain:delete
       xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
        <domain:name>{{ name }}</domain:name>
      </domain:delete>
    </delete>
    <clTRID>{{ clTRID }}</clTRID>
  </command>
</epp>');
        $r = $s->write($xml, __FUNCTION__);
    } catch(exception $e) {
        $return = array(
            'error' => $e->getMessage()
        );
    }

    if (!empty($s)) {
        $s->logout($params['registrarprefix']);
    }

    return $return;
}

function epp_AdminCustomButtonArray($params = array())
{
}

function epp_TransferSync($params = array())
{
    _epp_log(__FUNCTION__, $params);
    $return = array();
    try {
        $s = _epp_startEppClient($params);
        $from = $to = array();
        $from[] = '/{{ name }}/';
        $to[] = htmlspecialchars($params['sld'] . '.' . $params['tld']);
        $from[] = '/{{ clTRID }}/';
        $clTRID = str_replace('.', '', round(microtime(1), 3));
        $to[] = htmlspecialchars($params['registrarprefix'] . '-domain-transfer-' . $clTRID);
        $xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <transfer op="query">
      <domain:transfer
       xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
       xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
        <domain:name>{{ name }}</domain:name>
      </domain:transfer>
    </transfer>
    <clTRID>{{ clTRID }}</clTRID>
  </command>
</epp>');
        $r = $s->write($xml, __FUNCTION__);
        $r = $r->response->resData->children('urn:ietf:params:xml:ns:domain-1.0')->trnData;
        $trStatus = (string)$r->trStatus;
        $expDate = (string)$r->exDate;
        $updatedDomainTrStatus = Capsule::table('tbldomains')->where('id', $params['domainid'])->update(['trstatus' => $trStatus]);

        switch ($trStatus) {
            case 'pending':
                $return['completed'] = false;
            break;
            case 'clientApproved':
            case 'serverApproved':
                $return['completed'] = true;
                $return['expirydate'] = date('Y-m-d', is_numeric($expDate) ? $expDate : strtotime($expDate));
            break;
            case 'clientRejected':
            case 'clientCancelled':
            case 'serverCancelled':
                $return['failed'] = true;
                $return['reason'] = $trStatus;
            break;
            default:
                $return = array(
                    'error' => sprintf('invalid transfer status: %s', $trStatus)
                );
            break;
        }

        return $return;
    } catch(exception $e) {
        $return = array(
            'error' => $e->getMessage()
        );
    }

    if (!empty($s)) {
        $s->logout($params['registrarprefix']);
    }

    return $return;
}

function epp_Sync($params = array())
{
    _epp_log(__FUNCTION__, $params);
    $return = array();
    try {
        $s = _epp_startEppClient($params);
        $from = $to = array();
        $from[] = '/{{ name }}/';
        $to[] = htmlspecialchars($params['sld'] . '.' . $params['tld']);
        $from[] = '/{{ clTRID }}/';
        $clTRID = str_replace('.', '', round(microtime(1), 3));
        $to[] = htmlspecialchars($params['registrarprefix'] . '-domain-info-' . $clTRID);
        $xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <info>
      <domain:info
       xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
       xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
        <domain:name hosts="all">{{ name }}</domain:name>
      </domain:info>
    </info>
    <clTRID>{{ clTRID }}</clTRID>
  </command>
</epp>');
        $r = $s->write($xml, __FUNCTION__);
        $r = $r->response->resData->children('urn:ietf:params:xml:ns:domain-1.0')->infData;
        $expDate = (string)$r->exDate;
        $expTimestamp = strtotime($expDate);
        $crDate = (string)$r->crDate;
        $crTimestamp = strtotime($crDate);

        if ($expTimestamp === false) {
            return array(
                'error' => 'Empty expDate date for domain: ' . $params['domain']
            );
        }

        $expDateFormatted = date('Y-m-d', $expTimestamp);
        $crDateFormatted = date('Y-m-d', $crTimestamp);

        if ($expDateFormatted == $crDateFormatted) {
            return array(
                'error' => 'Creation date = expiry date: ' . $params['domain']
            );
        }

        if ($expTimestamp < time()) {
            return array(
                'expirydate' =>  $expDateFormatted,
                'expired'    =>  true
            );            
        } else {
            return array(
                'expirydate' =>  $expDateFormatted,
                'active'     =>  true
            );
        }
    } catch(exception $e) {
        $return = array(
            'error' => $e->getMessage()
        );
    }

    if (!empty($s)) {
        $s->logout($params['registrarprefix']);
    }

    return $return;
}

class epp_epp_client

{
    var $socket;
    var $isLogined = false;
    var $params;
    function __construct($params)
    {
        $this->params = $params;
        $verify_peer = false;
        if ($params['verify_peer'] == 'on') {
            $verify_peer = true;
        }
        $ssl = array(
            'verify_peer' => $verify_peer,
            'cafile' => $params['cafile'],
            'local_cert' => $params['local_cert'],
            'local_pk' => $params['local_pk'],
            'passphrase' => $params['passphrase']
        );
        $host = $params['host'];
        $port = $params['port'];

        if ($host) {
            $this->connect($host, $port, $ssl);
        }
    }

    function connect($host, $port = 700, $ssl, $timeout = 30)
    {
        if ($host != $this->params['host']) {
            throw new exception("Unknown EPP server");
        }
        
        $opts = array(
            'ssl' => array(
                'verify_peer' => $ssl['verify_peer'],
                'verify_peer_name' => false,
                'verify_host' => false,
                'passphrase' => $ssl['passphrase'],
                'allow_self_signed' => true
            )
        );
        $context = stream_context_create($opts);
        $this->socket = stream_socket_client("ssl://{$host}:{$port}", $errno, $errmsg, $timeout, STREAM_CLIENT_CONNECT, $context);


        if (!$this->socket) {
            _epp_log($errmsg);
            throw new exception("Cannot connect to server '{$host}': {$errmsg}");
        }

        return $this->read();
    }

    function login($login, $pwd, $prefix)
    {
        $from = $to = array();
        $from[] = '/{{ clID }}/';
        $to[] = htmlspecialchars($login);
        $from[] = '/{{ pw }}/';
        $to[] = $pwd;
        $from[] = '/{{ clTRID }}/';
        $clTRID = str_replace('.', '', round(microtime(1), 3));
        $to[] = htmlspecialchars($prefix . '-login-' . $clTRID);
        $xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <login>
      <clID>{{ clID }}</clID>
      <pw><![CDATA[{{ pw }}]]></pw>
      <options>
        <version>1.0</version>
        <lang>en</lang>
      </options>
      <svcs>
        <objURI>urn:ietf:params:xml:ns:domain-1.0</objURI>
        <objURI>urn:ietf:params:xml:ns:contact-1.0</objURI>
        <svcExtension>
          <extURI>http://www.nic.lv/epp/schema/lvdomain-ext-1.0</extURI>
          <extURI>http://www.nic.lv/epp/schema/lvcontact-ext-1.0</extURI>
          <extURI>urn:ietf:params:xml:ns:secDNS-1.1</extURI>
        </svcExtension>
      </svcs>
    </login>
    <clTRID>{{ clTRID }}</clTRID>
  </command>
</epp>');
        $r = $this->write($xml, __FUNCTION__);
        $this->isLogined = true;
        return true;
    }

    function logout($prefix)
    {
        if (!$this->isLogined) {
            return true;
        }

        $from = $to = array();
        $from[] = '/{{ clTRID }}/';
        $clTRID = str_replace('.', '', round(microtime(1), 3));
        $to[] = htmlspecialchars($prefix . '-logout-' . $clTRID);
        $xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
    <logout/>
    <clTRID>{{ clTRID }}</clTRID>
  </command>
</epp>');
        $r = $this->write($xml, __FUNCTION__);
        $this->isLogined = false;
        return true;
    }

    function read()
    {
        _epp_log('================= read-this =================', $this);
        $hdr = stream_get_contents($this->socket, 4);
        if ($hdr === false) {
            throw new exception('Connection appears to have closed.');
        }
        if (strlen($hdr) < 4) {
            throw new exception('Failed to read header from the connection.');
        }
        $unpacked = unpack('N', $hdr);
        $xml = fread($this->socket, ($unpacked[1] - 4));
        $xml = preg_replace('/></', ">\n<", $xml); 
        _epp_log('================= read =================', $xml);
        return $xml;
    }

    function write($xml, $action = 'Unknown')
    {
        _epp_log('================= send-this =================', $this);
        _epp_log('================= send =================', $xml);
        if (fwrite($this->socket, pack('N', (strlen($xml) + 4)) . $xml) === false) {
            throw new exception('Error writing to the connection.');
        }
        $r = simplexml_load_string($this->read());
        _epp_modulelog($xml, $r, $action);
        if (isset($r->response) && $r->response->result->attributes()->code >= 2000) {
            $errorMessages = [];

            foreach ($r->response->result as $result) {
                if ($result->attributes()->code >= 2000) {
                    $errorMessages[] = $result->msg;
                }
            }

            if (!empty($errorMessages)) {
                throw new Exception(implode("; ", $errorMessages));
            }
        }
        return $r;
    }

    function disconnect()
    {
        $result = fclose($this->socket);
        if (!$result) {
            throw new exception('Error closing the connection.');
        }
        $this->socket = null;
        return $result;
    }

    function generateObjectPW($objType = 'none')
    {
        $result = '';
        $uppercaseChars = "ABCDEFGHIJKLMNOPQRSTUVWXYZ";
        $lowercaseChars = "abcdefghijklmnopqrstuvwxyz";
        $numbers = "1234567890";
        $specialSymbols = "!=+-";
        $minLength = 16;
        $maxLength = 16;
        $length = mt_rand($minLength, $maxLength);

        // Include at least one character from each set
        $result .= $uppercaseChars[mt_rand(0, strlen($uppercaseChars) - 1)];
        $result .= $lowercaseChars[mt_rand(0, strlen($lowercaseChars) - 1)];
        $result .= $numbers[mt_rand(0, strlen($numbers) - 1)];
        $result .= $specialSymbols[mt_rand(0, strlen($specialSymbols) - 1)];

        // Append random characters to reach the desired length
        while (strlen($result) < $length) {
            $chars = $uppercaseChars . $lowercaseChars . $numbers . $specialSymbols;
            $result .= $chars[mt_rand(0, strlen($chars) - 1)];
        }

        return $result;
    }
    
    function generateRandomString() 
    {
        $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $randomString = '';
        for ($i = 0; $i < 16; $i++) {
            $randomString .= $characters[rand(0, strlen($characters) - 1)];
        }
        return $randomString;
    }

}

function _epp_modulelog($send, $responsedata, $action)
{
    $from = $to = array();
    $from[] = "/<clID>[^<]*<\/clID>/i";
    $to[] = '<clID>Not disclosed clID</clID>';
    $from[] = "/<pw>[^<]*<\/pw>/i";
    $to[] = '<pw>Not disclosed pw</pw>';
    $sendforlog = preg_replace($from, $to, $send);
    logModuleCall('epp', $action, $sendforlog, $responsedata);
}

function _epp_log($func, $params = false)
{
    $date = date('Y-m-d');
    $logDir = dirname(__DIR__, 4) . '/epp_logs';
    $logFile = $logDir . "/epp-{$date}.log";
    $handle = fopen($logFile, 'a');
    ob_start();
    echo "\n================= $func =================\n";
    print_r($params);
    $text = ob_get_contents();
    ob_end_clean();
    fwrite($handle, $text);
    fclose($handle);
}

function _epp_create_table()
{
    if (!Capsule::schema()->hasTable('epp_domain_status')) {
        try {
            Capsule::schema()->create('epp_domain_status',
            function (Blueprint $table)
            {
                /** @var \Illuminate\Database\Schema\Blueprint $table */
                $table->increments('id');
                $table->integer('domain_id');

                $table->enum('status', array(
                    'clientDeleteProhibited',
                    'clientHold',
                    'clientRenewProhibited',
                    'clientTransferProhibited',
                    'clientUpdateProhibited',
                    'inactive',
                    'ok',
                    'pendingCreate',
                    'pendingDelete',
                    'pendingRenew',
                    'pendingTransfer',
                    'pendingUpdate',
                    'serverDeleteProhibited',
                    'serverHold',
                    'serverRenewProhibited',
                    'serverTransferProhibited',
                    'serverUpdateProhibited'
                ))->default('ok');
                $table->unique(array(
                    'domain_id',
                    'status'
                ));
                $table->foreign('domain_id')->references('id')->on('tbldomains')->onDelete('cascade');
            });
        } catch(Exception $e) {
            echo "Unable to create table 'epp_domain_status': {$e->getMessage() }";
        }
    }
}

function _epp_create_column()
{
    if (!Capsule::schema()->hasColumn('tbldomains', 'trstatus')) {
        try {
            Capsule::schema()->table('tbldomains',
            function (Blueprint $table)
            {
                $table->enum('trstatus', array(
                    'clientApproved',
                    'clientCancelled',
                    'clientRejected',
                    'pending',
                    'serverApproved',
                    'serverCancelled'
                ))->nullable()->after('status');
            });
        } catch(Exception $e) {
            echo "Unable to alter table 'tbldomains' add column 'trstatus': {$e->getMessage() }";
        }
    }
}

?>