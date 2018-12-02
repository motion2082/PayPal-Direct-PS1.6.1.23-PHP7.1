<?php

include(dirname(__FILE__).'/../../config/config.inc.php');
include(dirname(__FILE__).'/../../init.php');
include(dirname(__FILE__).'/npaypalpro.php');

$paypalpro = new NpaypalPro();

function fillShipping($shipping_address)
{
	$shipping = array();
	$shipping['recipient_name'] = $shipping_address->address1;
	$shipping['line1'] = $shipping_address->address1;
	$shipping['city'] = $shipping_address->city;
	$result = Db::getInstance(_PS_USE_SQL_SLAVE_)->getRow('SELECT iso_code FROM '
	._DB_PREFIX_.'country WHERE id_country = '.$shipping_address->id_country);

	$shipping['country_code'] = $result['iso_code'];
	$shipping['postal_code'] = $shipping_address->postcode;
	return $shipping;
}

if ($paypalpro->active)
{
	$context = Context::getContext();

	/* Loading current billing address from PrestaShop */
	if (!isset($context->cart->id)
	|| empty($context->cart->id)
	|| !isset($context->cart->id_address_invoice)
	|| empty($context->cart->id_address_invoice))
		die('No active shopping cart');
	$billing_address = new Address((int)$context->cart->id_address_invoice, false);
	$shipping_address = new Address((int)$context->cart->id_address_delivery, false);

	if (isset($billing_address) && isset($shipping_address) && isset($context->customer->email))
	{
		$shipping = fillShipping($shipping_address);

		$currency = $context->currency->iso_code;

		$transaction = array();
		$transaction['intent'] = 'sale';
		$transaction['payer'] = array();
		$transaction['payer']['payment_method'] = 'credit_card';
		$transaction['payer']['payer_info'] = array();
		$transaction['payer']['payer_info']['email'] = $context->customer->email;
		$transaction['payer']['payer_info']['shipping_address'] = $shipping;

		/* Retrieving Country and State ISO codes */
		$billing_country = new Country((int)$billing_address->id_country);
		if (isset($billing_country))
		{
			if ($billing_address->id_state)
				$billing_state = new State((int)$billing_address->id_state);
			if ($shipping_address->id_state)
				$shipping_state = new State((int)$shipping_address->id_state);
		}

		$cart = new Cart((int)$context->cart->id);

		if (isset($cart))
		{
            $items = array();
			$tab = $cart->getProducts();
            $discounts = $cart->getDiscounts();

			/* Update shipping price */
			if (preg_match('/\.(\d{2})$|^\d*$/', $cart->getTotalShippingCost()))
				$shipping_price = $cart->getTotalShippingCost();
			else
				$shipping_price = $cart->getTotalShippingCost().'0';

			$shipping_item = array(
				'quantity' => 1,
				'name' => 'Shipping',
				'price' => $shipping_price,
				'currency' => $currency
			);

			if ($cart->gift)
			{
				/* Update gift price */
				if (preg_match('/\.(\d{2})$|^\d*$/', $cart->getGiftWrappingPrice()))
					$gift_price = $cart->getGiftWrappingPrice();
				else
					$gift_price = $cart->getGiftWrappingPrice().'0';

				$gift = array(
					'quantity' => 1,
					'name' => 'Gift',
					'price' => $gift_price,
					'currency' => $currency
				);
			}

			if (isset($tab))
			{
				foreach ($tab as $product)
				{
					$item = array();
					$item['quantity'] = $product['quantity'];
					$item['name'] = $product['name'];
                    $price = round($product['price_wt'], 2);
					if (preg_match('/\.(\d{2})$|^\d*$/', $price))
						$item_price = $price;
					else
						$item_price = $price.'0';
					$item['price'] = $item_price;
					$item['currency'] = $currency;
					array_push($items, $item);
				}
				array_push($items, $shipping_item);
				if ($cart->gift)
					array_push($items, $gift);
			}

            /* get Discount Price */
            if (!empty($discounts))
            {
                foreach ($discounts as $discount)
                {
                    /* Update discount price */
                    $formDiscount = number_format($discount['value_tax_exc'], 2);
                    if (preg_match('/\.(\d{2})$|^\d*$/', $formDiscount))
                        $discount_price = '-'.$formDiscount;
                    else
                        $discount_price = '-'.$formDiscount.'0';

                    $disc = array(
                        'quantity' => 1,
                        'name' => $discount['name'],
                        'price' => $discount_price,
                        'currency' => $currency
                    );

                }
                array_push($items, $disc);
            }

			$item_list = array();
			$item_list['items'] = $items;
			$item_list['shipping_address'] = $shipping;

			$credit_card = array();
			$credit_card['number'] = preg_replace('/\s+/', '', Tools::getValue('paypal_pro_cc_number'));
			$credit_card['type'] = Tools::strtolower(Tools::getValue('paypal_pro_cc_type'));
			$credit_card['expire_month'] = (int)Tools::getValue('paypal_pro_cc_exp_month');
			$credit_card['expire_year'] = (int)Tools::getValue('paypal_pro_cc_year');
			$credit_card['cvv2'] = Tools::getValue('paypal_pro_cc_cvv');
			$credit_card['first_name'] = Tools::substr(Tools::getValue('paypal_pro_cc_firstname'), 0, 25);
			$credit_card['last_name'] = Tools::substr(Tools::getValue('paypal_pro_cc_lastname'), 0, 25);
			$credit_card['billing_address'] = array();
			$credit_card['billing_address']['line1'] = $billing_address->address1;
			if (isset($billing_address->address2) && !empty($billing_address->address2))
				$credit_card['billing_address']['line2'] = $billing_address->address2;
			$credit_card['billing_address']['city'] = $billing_address->city;
			if ($billing_address->id_state && isset($billing_state->iso_code))
				$credit_card['billing_address']['state'] = Tools::strtoupper(Tools::substr($billing_state->iso_code, 0, 2));
			$credit_card['billing_address']['postal_code'] = $billing_address->postcode;
			$credit_card['billing_address']['country_code'] = Tools::strtoupper(Tools::substr($billing_country->iso_code, 0, 2));
			$transaction['payer']['funding_instruments'] = array(array('credit_card' => $credit_card));

			$amount = array();
			if (preg_match('/\.(\d{2})$|^\d*$/', $context->cart->getOrderTotal(true)))
				$amount['total'] = $context->cart->getOrderTotal(true);
			else
				$amount['total'] = $context->cart->getOrderTotal(true).'0';
			$amount['currency'] = $context->currency->iso_code;

            /* Total Amount check */
            $diff = totalAmountCheck($amount['total'], $item_list['items']); // this verification is made because of the US taxes

            /* If amountcheck != $amount */
            if ($diff != 0)
            {
                $price = $diff > 0 ? '-'.$diff : abs($diff);
                $item = array();
                $item['quantity'] = 1;
                $item['name'] = 'Voucher taxes';
                $item['price'] = $price;
                $item['currency'] = $currency;
                array_push($item_list['items'], $item);
            }

			$transaction['transactions'] = array(array('amount' => $amount, 'description' => 'PrestaShop - Customer: '
			.$context->customer->email.' | Shopping Cart ID: '.(int)$context->cart->id, 'item_list' => $item_list));

			/* Make payment */
			$paypalpro->apiRequest('v1/payments/payment', $transaction);
		}
	}
}

function totalAmountCheck($amount, $items)
{
    $amountCheck = $diff = 0;

    foreach ($items as $item)
        $amountCheck += number_format( ($item['quantity'] * $item['price']), 2);

    if ($amountCheck != $amount)
        $diff = number_format($amountCheck - $amount, 2);

    return $diff;

}
