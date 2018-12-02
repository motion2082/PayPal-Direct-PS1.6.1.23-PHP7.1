<?php


if (!defined('_PS_VERSION_'))
	exit;

class NpaypalPro extends PaymentModule
{
	public $limited_countries = array('us', 'gb', 'au');
	public $limited_currencies = array('USD', 'CAD', 'GBP', 'EUR', 'JPY', 'AUD');
	public $cards = array('visa' => 1, 'mastercard' => 2, 'amex' => 3, 'discover' => 4);
	/*
		PayPal Payments Pro (Direct Payment) => Only in the U.S.
		Website Payments Pro => UK, Canada
	*/

	public function __construct()
	{
		$this->name = 'npaypalpro';
		$this->tab = 'payments_gateways';
		$this->version = '1.3.7';
		$this->module_key = 'c38d46e8a527b9261dc187aa40a9e253';
		$this->author = 'PrestaShop';
		$this->need_instance = 0;
		$this->ps_versions_compliancy = array('min' => '1.5', 'max' => '1.6');
		$this->bootstrap = true;
		$this->display = 'view';

		parent::__construct();

		$this->meta_title = $this->l('PayPal Pro');
		$this->displayName = $this->l('PayPal Pro');
		$this->description = $this->l('Start accepting credit card payments today, directly from your shop!');

		/* Use a specific name to bypass an Order confirmation controller check */
		if (in_array(Tools::getValue('controller'), array('orderconfirmation', 'order-confirmation')))
			$this->displayName = $this->l('Payment by Paypal Pro');
	}

	public function install()
	{
		if (is_callable('curl_init') === false)
		{
			$this->warning = $this->l('To be able to use this module, please activate cURL (PHP extension).');
			return false;
		}

		return parent::install()
			&& $this->registerHook('payment')
			&& $this->registerHook('orderConfirmation')
			&& $this->registerHook('adminOrder')
			&& Configuration::updateValue('PAYPALPRO_SANDBOX', 1)
			&& Db::getInstance()->Execute('
		CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'paypalpro` (
		  `id_paypal_pro` int(11) NOT NULL AUTO_INCREMENT,
		  `id_cart` int(10) unsigned NOT NULL,
		  `id_paypal` varchar(17) NOT NULL,
		  `cc_last_digits` varchar(4) NOT NULL,
		  `cc_type` tinyint(4) NOT NULL,
		  `result` tinyint(4) NOT NULL,
		  `date_add` datetime NOT NULL,
		  PRIMARY KEY (`id_paypal_pro`),
		  KEY `id_cart` (`id_cart`)
		) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8 AUTO_INCREMENT=1');
	}

	public function uninstall()
	{
		Db::getInstance()->Execute('DROP TABLE `'._DB_PREFIX_.'paypalpro`');

		return parent::uninstall() && Configuration::deleteByName('PAYPALPRO_SANDBOX')
		&& Configuration::deleteByName('PAYPALPRO_CLIENT_ID') && Configuration::deleteByName('PAYPALPRO_SECRET_KEY');
	}

	public static function getCurrenciesByIdShop($id_shop = 0)
	{
		return Db::getInstance()->executeS('
		SELECT *
		FROM `'._DB_PREFIX_.'currency` c
		LEFT JOIN `'._DB_PREFIX_.'currency_shop` cs ON (cs.`id_currency` = c.`id_currency`)
		'.($id_shop ? ' WHERE cs.`id_shop` = '.(int)$id_shop : '').'
		ORDER BY `name` ASC');
	}

	public function getContent()
	{
		if (version_compare(_PS_VERSION_, '1.6.0.0') >= 0)
			$this->context->controller->addCSS(array($this->_path.'views/css/admin.css'));
		else
			$this->context->controller->addCSS(array($this->_path.'views/css/admin1_5.css'));

		$output = '';
		if (Tools::isSubmit('submit'.$this->name))
		{
			$paypalpro = Tools::getValue('PAYPALPRO_CLIENT_ID');
			if (!isset($paypalpro) || !(Tools::getValue('PAYPALPRO_SECRET_KEY')))
				$output = $this->displayError('Client ID and Secret Key fields are mandatory');
			else
			{
				Configuration::updateValue('PAYPALPRO_SANDBOX', (int)Tools::getValue('PAYPALPRO_SANDBOX'));
				Configuration::updateValue('PAYPALPRO_CLIENT_ID', Tools::getValue('PAYPALPRO_CLIENT_ID'));
				Configuration::updateValue('PAYPALPRO_SECRET_KEY', Tools::getValue('PAYPALPRO_SECRET_KEY'));

				$output = $this->displayConfirmation($this->l('Settings updated successfully'));

				if ($this->apiRequest(null, null, true))
					$output .= $this->displayConfirmation($this->l('Test connection to PayPal successful, these credentials seem to be valid'));
				else
					$output .= $this->displayError('Test connection to PayPal failed, your Client ID or Secret key are invalid, please try again');
			}
		}

		/* Check if at least one supported currency is available */
		$currency_flag = 0;
		$currencies = $this->getCurrenciesByIdShop((int)$this->context->shop->id);
		foreach ($currencies as $currency)
			if ($currency['active'] && in_array($currency['iso_code'], $this->limited_currencies))
			{
				$currency_flag = 1;
				break;
			}
		if (!$currency_flag)
			$output .= $this->displayError('This module only supports the following currencies:
 				USD, CAD, EUR and GBP. Please enable at least one of these currencies.');

		/* Check if SSL is enabled */
		if (!Configuration::get('PS_SSL_ENABLED'))
			$output .= $this->displayError('A SSL certificate is required to process credit card payments.
				Please make sure you install one before accepting any credit card payments.');

		$this->context->smarty->assign('paypal_pro_output', $output.$this->renderForm());

		return $this->display(__FILE__, 'views/templates/admin/configuration.tpl');
	}

	public function renderForm()
	{
		$fields_form = array(
			'form' => array(
				'legend' => array(
					'title' => $this->l('If you already have a PayPal Payments Pro account, configure it:'),
					'icon' => 'icon-cogs'
				),
				'input' => array(
					array(
						'type' => 'text',
						'label' => $this->l('PayPal Client ID'),
						'name' => 'PAYPALPRO_CLIENT_ID',
						'size' => 20,
						'class' => 'fixed-width-xxl',
						'required' => true
					),
					array(
						'type' => 'free',
						'label' => $this->l('PayPal Secret Key'),
						'name' => 'PAYPALPRO_SECRET_KEY',
						'size' => 20,
						'required' => true
					),
					array(
						'type' => 'radio',
						'label' => $this->l('Test mode (also called "Sandbox")'),
						'name' => 'PAYPALPRO_SANDBOX',
						'is_bool' => true,
						'desc' => $this->l('PayPal Sandbox mode (for testing purpose only)').'<br /><br />
						<div id="paypal_pro_card" style="font-style: normal; padding: 10px; border-radius: 3px; margin-top: -15px;
						border-left: solid 3px #57BCD9; background: #DBF0F7; width: 250px; display: none;">'
						.$this->l('You can use this test Credit card:').'<br />'.
						$this->l('Number:').' <img src="../modules/'.$this->name
						.'/views/img/cc-visa.png" style="vertical-align: texr-top;" alt="" /> <b>4916064324171157</b><br />'.
						$this->l('Expiration date:').' <b>11/2018</b><br />'.
						$this->l('CVV:').' <b>123</b></div>
						<script type="text/javascript">
							$(\'input[name=PAYPALPRO_SANDBOX]\').change(function()
							{ if ($(this).val() == 1) $(\'#paypal_pro_card\').show(); else $(\'#paypal_pro_card\').hide(); });
							if ($(\'input[name=PAYPALPRO_SANDBOX]:checked\').val() == 1) $(\'#paypal_pro_card\').show();</script>',
						'values' => array(
							array(
								'id' => 'active_on',
								'value' => 1,
								'label' => $this->l('Yes')
							),
							array(
								'id' => 'active_off',
								'value' => 0,
								'label' => $this->l('No')
							)
						),
					)
				),
			'submit' => array(
				'title' => $this->l('Save'),
				'class' => 'btn btn-default pull-right button')
			),
		);

		$helper = new HelperForm();
		$helper->show_toolbar = false;
		$helper->table = $this->table;
		$lang = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
		$helper->default_form_language = $lang->id;
		$helper->allow_employee_form_lang =	(int)Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG')
			? (int)Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
		$this->fields_form = array();

		$helper->identifier = $this->identifier;
		$helper->submit_action = 'submit'.$this->name;
		$helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
			.'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
		$helper->token = Tools::getAdminTokenLite('AdminModules');
		$helper->tpl_vars = array(
			'fields_value' => $this->getConfigFieldsValues(),
			'languages' => $this->context->controller->getLanguages(),
			'id_language' => $this->context->language->id
		);

		return $helper->generateForm(array($fields_form));
	}

	private function getConfigFieldsValues()
	{
		return array(
			'PAYPALPRO_CLIENT_ID' => pSQL(Tools::getValue('PAYPALPRO_CLIENT_ID', Configuration::get('PAYPALPRO_CLIENT_ID'))),
			'PAYPALPRO_SECRET_KEY' => '<input type="password" class="form-control-static fixed-width-xxl" id="PAYPALPRO_SECRET_KEY"
			name="PAYPALPRO_SECRET_KEY" value="'.Tools::safeOutput(Tools::getValue('PAYPALPRO_SECRET_KEY',
			Configuration::get('PAYPALPRO_SECRET_KEY'))).'" />',
			'PAYPALPRO_SANDBOX' => Tools::getValue('PAYPALPRO_SANDBOX', (int)Configuration::get('PAYPALPRO_SANDBOX')),
		);
	}

	private function addTentative($digits, $type, $result, $id_paypal, $id_cart = 0)
	{
		if (!is_numeric($type))
			$type = isset($this->cards[Tools::strtolower($type)]) ? (int)$this->cards[Tools::strtolower($type)] : 0;
		if ($id_cart == 0)
			$id_cart = (int)$this->context->cart->id;

		Db::getInstance()->Execute('
		INSERT INTO '._DB_PREFIX_.'paypalpro (id_paypal, id_cart, cc_last_digits, cc_type, result, date_add)
		VALUES ("'.pSQL($id_paypal).'", \''.(int)$id_cart.'\', \''.pSQL(Tools::substr($digits, 0, 4)).'\', '.(int)$type.', '.(int)$result.', NOW())');
	}

	public function hookPayment()
	{
		$html = '';
		$html .= '<link href="'.$this->_path.'/views/css/front.css" rel="stylesheet" type="text/css" media="all" />';
		$html .= '<script src="'.$this->_path.'views/js/front.js" type="text/javascript" ></script>';
		$html .= $this->display(__FILE__, 'views/templates/hook/payment.tpl');

		return $html;
	}

	public function hookOrderConfirmation($params)
	{
		$this->context->smarty->assign('paypal_pro_order_reference', pSQL($params['objOrder']->reference));
		if ($params['objOrder']->module == $this->name)
			return $this->display(__FILE__, 'views/templates/front/order-confirmation.tpl');
	}

	public function apiRequest($endpoint, $data = '', $auth_check = false, $tab_pay = array())
	{
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, 'https://api.'.((int)Configuration::get('PAYPALPRO_SANDBOX') ? 'sandbox.' : '').'paypal.com/v1/oauth2/token');
		curl_setopt($ch, CURLOPT_USERPWD, pSQL(Configuration::get('PAYPALPRO_CLIENT_ID')).':'.Configuration::get('PAYPALPRO_SECRET_KEY'));
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !in_array($_SERVER['REMOTE_ADDR'], array('localhost', '::1')));
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_VERBOSE, true);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, 'grant_type=client_credentials');
		$result = curl_exec($ch);
		curl_close($ch);

		/* Check if authentication is valid */
		if ($result)
		{
			$result_json = Tools::jsonDecode($result);
			if (isset($result_json->access_token))
			{
				if ($auth_check)
					return true;

				if ($data != '{}')
					$enc_data = Tools::jsonEncode($data);
				else
					$enc_data = $data;

				$ch2 = curl_init();
				curl_setopt($ch2, CURLOPT_URL, 'https://api.'.(Configuration::get('PAYPALPRO_SANDBOX') ? 'sandbox.' : '').'paypal.com/'.$endpoint);
				curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, !in_array($_SERVER['REMOTE_ADDR'], array('127.0.0.1', '::1')));
				curl_setopt($ch2, CURLOPT_SSL_VERIFYHOST, 2);
				curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($ch2, CURLOPT_VERBOSE, true);
				curl_setopt($ch2, CURLOPT_POST, true);
				curl_setopt($ch2, CURLOPT_POSTFIELDS, $enc_data);
				curl_setopt($ch2, CURLOPT_HTTPHEADER, array(
					'Content-Type: application/json',
					'Authorization: Bearer '.$result_json->access_token,
					'PayPal-Partner-Attribution-Id: PrestashopUS_Cart'
				));

				$result = curl_exec($ch2);
				$json_result = Tools::jsonDecode($result);
				curl_close($ch2);

				if (isset($json_result->state))
				{
					/* The payment was approved */
					if ($json_result->state == 'approved' && $json_result->intent == 'sale' && isset($json_result->id) && $json_result->id)
					{
						$id_paypal = $json_result->transactions[0]->related_resources[0]->sale->id;
						$message = 'Transaction ID: '.$json_result->id;
						/* Check currency $json_result->transactions[0]->amount->currency */
						try
						{
							$this->validateOrder((int)$this->context->cart->id, (int)Configuration::get('PS_OS_PAYMENT'),
							(float)$json_result->transactions[0]->amount->total, $this->l('Payment by Paypal Pro'), $message,
							array(), null, false, $this->context->customer->secure_key);
						}
						catch (PrestaShopException $e)
						{
							$this->_error[] = (string)$e->getMessage();
						}

						$this->addTentative(Tools::substr($data['payer']['funding_instruments'][0]['credit_card']['number'], 0, 4),
						$data['payer']['funding_instruments'][0]['credit_card']['type'], 1, $id_paypal);
						$id_order = Order::getOrderByCartId($this->context->cart->id);
						die(Tools::jsonEncode(array('code' => '1', 'url' => __PS_BASE_URI__.'index.php?controller=order-confirmation&id_cart='.
						(int)$this->context->cart->id.'&id_module='.(int)$this->id.'&id_order='.(int)$id_order.
						'&key='.$this->context->customer->secure_key)));

					}
					/* Refund Payment was approved */
					else if ($json_result->state == 'completed' && !empty($tab_pay['id_cart']))
					{
						$this->addTentative($tab_pay['last_digits'], $tab_pay['cc_type'], 2, 0, $tab_pay['id_cart']);
						$id_order = Order::getOrderByCartId($tab_pay['id_cart']);
						$order = new Order($id_order);
						/* Refund State */
						$order->setCurrentState(7);
						Tools::redirect($tab_pay['redirect']);
					}
					/* The payment was declined */
					else
					{
						$this->addTentative(Tools::substr($data['payer']['funding_instruments'][0]['credit_card']['number'], 0, 4),
						$data['payer']['funding_instruments'][0]['credit_card']['type'], 0, 0);
						if ($json_result->state == 'expired')
							die(Tools::jsonEncode(array('code' => '0', 'msg' => $this->l('Payment declined. This card has expired, please use another one.'))));
						else
							die(Tools::jsonEncode(array('code' => '0', 'msg' => $this->l('Payment declined. Please use another card.'))));
					}
				}
				else
				{
					if ($json_result->name == 'TRANSACTION_REFUSED' && !empty($tab_pay))
						Tools::redirect($tab_pay['redirect']);
					else
					{
						$this->addTentative(Tools::substr($data['payer']['funding_instruments'][0]['credit_card']['number'], 0, 4),
						$data['payer']['funding_instruments'][0]['credit_card']['type'], 0, 0);
						die(Tools::jsonEncode(array('code' => '0',
						'msg' => $this->l('Payment declined. Unknown error, please use another card or contact us.'))));
					}
				}
			}
			else
			{
				if ($auth_check)
					return false;

				die(Tools::jsonEncode(array('code' => '0', 'msg' => $this->l('Invalid PayPal Pro credentials, please check your configuration.'))));
			}
		}
		else
		{
			if ($auth_check)
				return false;

			die(Tools::jsonEncode(array('code' => '0', 'msg' => $this->l('Invalid PayPal Pro credentials, please check your configuration.'))));
		}
	}

	/*
	** @Method: name
	** @description: description
	**
	** @arg:
	** @return: (none)
	*/
	public function getCronLinkScript()
	{
		$cron_url = false;

		if (file_exists(dirname(__FILE__).'/refund.php'))
		{
			$cron_url = Tools::getShopDomain(true, true).__PS_BASE_URI__.basename(_PS_MODULE_DIR_).'/'.$this->name
				.'/refund.php?secure_key='.md5(_COOKIE_KEY_.Configuration::get('PS_SHOP_NAME'));
		}
		return $cron_url;
	}

	public function hookAdminOrder($params)
	{
		$this->context->controller->addJs($this->_path.'views/js/back.js');
		$cards = array(1 => 'visa', 2 => 'mastercard', 3 => 'amex',  4 => 'discover');
		$order = new Order((int)$params['id_order']);
		$currency = Currency::getCurrency($order->id_currency);
		$tenta = array();

		if (Validate::isLoadedObject($order) && $order->module == $this->name)
		{
			$tentatives = Db::getInstance()->ExecuteS('
			SELECT *
			FROM '._DB_PREFIX_.'paypalpro
			WHERE id_cart = '.(int)$order->id_cart.'
			ORDER BY date_add DESC');

			foreach ($tentatives as $tentative)
			{
				if ($tentative['result'] == 1)
					$result = '';
				else if ($tentative['result'] == 0)
					$result = 'n';
				else
					$result = 2;

				array_push($tenta, array(
					'date' => Tools::safeOutput($tentative['date_add']),
					'last_digits' => Tools::safeOutput($tentative['cc_last_digits']),
					'cards_type' => Tools::strtolower($cards[(int)$tentative['cc_type']]),
					'result' => $result
				));
			}

			$redirect = $_SERVER['HTTP_REFERER'];
			$refund = $this->getCronLinkScript();
			$this->context->smarty->assign('refund', $refund);
			$this->context->smarty->assign('tenta', $tenta);
			$this->context->smarty->assign('max', $order->total_paid_real);
			$this->context->smarty->assign('path', $this->_path);
			$this->context->smarty->assign('id_cart', (int)$this->context->cart->id);
			$this->context->smarty->assign('currency', $currency['iso_code']);
			$this->context->smarty->assign('redirect', $redirect);

			return $this->display(__FILE__, 'views/templates/admin/order.tpl');
		}
	}
}