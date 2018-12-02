<?php

if (!file_exists(dirname(__FILE__).'/../../config/config.inc.php')
|| !file_exists(dirname(__FILE__).'/npaypalpro.php'))
	die('ko');

require(dirname(__FILE__).'/../../config/config.inc.php');
require_once(dirname(__FILE__).'/npaypalpro.php');

if (!defined('_PS_VERSION_') || !Tools::isSubmit('secure_key'))
	die('ko');

$secure_key = md5(_COOKIE_KEY_.Configuration::get('PS_SHOP_NAME'));
if (empty($secure_key) || strcmp(Tools::getValue('secure_key'), $secure_key) !== 0)
	die('ko');

if (class_exists('Context'))
	$context = Context::getContext();

$npaypal = new Npaypalpro();

$submit = Tools::getValue('submit');
$id_cart = Tools::getValue('id_cart');
$amount = Tools::getValue('amount');
$currency = Tools::getValue('currency');
$redirect = Tools::getValue('redirect');

if ($submit == 'Total refund' && !empty($id_cart))
{
	$db = Db::getInstance()->ExecuteS('SELECT * FROM `'._DB_PREFIX_.'paypalpro` WHERE `id_cart` = '.(int)$id_cart);

	foreach ($db as $tab)
	{
		if ($tab['result'] == 1)
		{
			$tab_pay = array(
				'last_digits' => $tab['cc_last_digits'],
				'cc_type' => $tab['cc_type'],
				'id_cart' => $tab['id_cart'],
				'redirect' => $redirect
			);
			$npaypal->apiRequest('v1/payments/sale/'.$tab['id_paypal'].'/refund', '{}', false, $tab_pay);
		}
	}
}
else if ($submit == 'Partial refund' && !empty($id_cart) && !empty($amount))
{
	$db = Db::getInstance()->ExecuteS('SELECT * FROM `'._DB_PREFIX_.'paypalpro` WHERE `id_cart` = '.(int)$id_cart);
	$tmp = array();
	if (preg_match('/\.(\d{2})$|^\d*$/', $amount))
		$tmp['total'] = $amount;
	else
		$tmp['total'] = $amount.'0';
	$tmp['currency'] = $currency;
	$amt = array('amount' => $tmp);

	foreach ($db as $tab)
	{
		if ($tab['result'] == 1)
		{
			$tab_pay = array(
				'last_digits' => $tab['cc_last_digits'],
				'cc_type' => $tab['cc_type'],
				'id_cart' => $tab['id_cart'],
				'redirect' => $redirect
			);
			$npaypal->apiRequest('v1/payments/sale/'.$tab['id_paypal'].'/refund', $amt, false, $tab_pay);
		}
	}
}
else
	Tools::redirect($redirect);

?>