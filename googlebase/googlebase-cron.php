<?php

include(dirname(__FILE__).'/../../config/config.inc.php');
include(dirname(__FILE__).'/googlebase.php');

if (substr(Tools::encrypt('googlebase/cron'), 0, 10) != Tools::getValue('token') || !Module::isInstalled('googlebase'))
	die('Bad token');

$googlebase = new GoogleBase();

if (!defined('_PS_BASE_URL_'))
	define('_PS_BASE_URL_', Tools::getShopDomain(true));
if (!defined('_PS_BASE_URL_SSL_'))
	define('_PS_BASE_URL_SSL_', Tools::getShopDomainSsl(true));

$context = Context::getContext();

$protocol_link = (Configuration::get('PS_SSL_ENABLED')) ? 'https://' : 'http://';
$protocol_content = (isset($useSSL) && $useSSL && Configuration::get('PS_SSL_ENABLED')) ? 'https://' : 'http://';

$context->link = new Link($protocol_link, $protocol_content);
$context->employee = new Employee();
$context->controller = new ModuleFrontController();

echo $googlebase->do_crontask();