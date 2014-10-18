<?php

require_once('GiftdClient.php');

class Giftd_Cards_Model_Observer
{
    protected $client = false;

    public function init()
    {
        $api_key = Mage::getStoreConfig('giftd_cards/api_settings/api_key',Mage::app()->getStore());
        $user_id = Mage::getStoreConfig('giftd_cards/api_settings/user_id',Mage::app()->getStore());

        if(strlen($api_key) > 0 && strlen($user_id) > 0)
        {
            $this->client = new GiftdClient($user_id, $api_key);
            return true;
        }

        return false;
    }

    public  function updateApiKey(Varien_Event_Observer $observer)
    {
        //kk: do check if api_key or user_id values has changed
        if(self::init())
        {
            $response = $this->client->query('partner/get');
            if($response['type'] == 'data')
            {
                $config = new Mage_Core_Model_Config();
                $config->saveConfig('giftd_cards/api_settings/partner_token', $response['data']['code'], 'default', 0);
                $config->saveConfig('giftd_cards/api_settings/partner_token_prefix', $response['data']['token_prefix'], 'default', 0);

            }
        }

    }

    public function showMinimumSubtotlaError($limit)
    {
        Mage::getSingleton('checkout/session')->addError('To apply this coupon the subtotal should be at least '.$limit);
    }

    public function checkoutCoupon(Varien_Event_Observer $observer)
    {
        if($client = self::init())
        {
            $quote = Mage::helper('checkout/cart')->getQuote();
            if ($coupon_code = $quote->getCouponCode())
            {
                if($card = self::getGiftdCard($coupon_code))
                {
                    $subtotal = $quote->getSubtotal();
                    $client->charge($coupon_code, $card->amount_available, $subtotal, Mage::getSingleton('customer/session')->getCustomerId().'_'.$subtotal);
                }
            }
        }

    }


    public function processCoupon(Varien_Event_Observer $observer)
    {
        if($_REQUEST['remove'] == 1)
            return;

        $coupon_code = $_REQUEST['coupon_code'];
        $subTotal = Mage::helper('checkout/cart')->getQuote()->getSubtotal();

        $existedCoupon = Mage::getModel('salesrule/coupon')->load($coupon_code, 'code');
        if($existedCoupon->getRuleId())
        {
            $rule = Mage::getModel('salesrule/rule')->load($existedCoupon->getRuleId());
            if(strpos($rule->getData('name'), 'Giftd') === 0 && $rule->getData('discount_amount') > $subTotal)
            {
                $this->showMinimumSubtotlaError(number_format($rule->getData('discount_amount'), 2));
            }
            return;
        }

        if(self::init())
        {
            if($card = self::getGiftdCard($coupon_code))
            {
                $coupon_value = $card->amount_available;
                if ($coupon_value > $subTotal)
                {
                    showMinimumSubtotlaError($coupon_value);
                }
                self::generateRule("Giftd card ".$card->title.' ('.$card->owner_name.')', $coupon_code, $coupon_value);
            }
        }
    }

    public function getGiftdCard($the_coupon_code)
    {
        $prefix = Mage::getStoreConfig('giftd_cards/api_settings/partner_token_prefix',Mage::app()->getStore());
        if($this->client && strlen($the_coupon_code) > 0 && strpos($the_coupon_code, $prefix) === 0)
        {
            $card = $this->client->checkByToken($the_coupon_code);
            if($card && $card->token_status == Giftd_Card::TOKEN_STATUS_OK)
            {
                return $card;
            }
        }

        return false;
    }

    public function generateRule($name = null, $coupon_code = null, $discount = 0)
    {
        if ($name != null && $coupon_code != null)
        {
            $data = array(
              'product_ids' => null,
              'name' => sprintf($name, Mage::getSingleton('customer/session')->getCustomerId()),
              'description' => null,
              'is_active' => 1,
              'website_ids' => array(1),
              'customer_group_ids' => array(0,1,2,4,5),
              'coupon_type' => 2,
              'coupon_code' => $coupon_code,
              'uses_per_coupon' => 1,
              'uses_per_customer' => 1,
              'from_date' => null,
              'to_date' => null,
              'sort_order' => null,
              'is_rss' => 0,
              'conditions' => array(
                  "1" => array(
                      "type" => "salesrule/rule_condition_combine",
                      "aggregator" => "all",
                      "value" => "1",
                      "new_child" => null
                  ),
                  "1--1" => array(
                      "type" => "salesrule/rule_condition_address",
                      "attribute" => "base_subtotal",
                      "operator" => ">=",
                      "value" => $discount
                  )
              ),
              'simple_action' => 'cart_fixed',
              'discount_amount' => $discount,
              'discount_qty' => 0,
              'discount_step' => null,
              'apply_to_shipping' => 0,
              'simple_free_shipping' => 0,
              'stop_rules_processing' => 0,
              'store_labels' => array($name)
            );

            $model = Mage::getModel('salesrule/rule');
            $validateResult = $model->validateData(new Varien_Object($data));

            if ($validateResult == true)
            {
                try {
                    $model->loadPost($data);
                    $model->save();
                } catch (Exception $e) {
                    Mage::log($e->getMessage());
                }
            }
        }
    }
}