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
            $response = $this->client->query('bitrix/getData');
            if($response['type'] == 'data')
            {
                $config = new Mage_Core_Model_Config();
                $config->saveConfig('giftd_cards/api_settings/partner_token', $response['data']['partner_code'], 'default', 0);
                $config->saveConfig('giftd_cards/api_settings/partner_token_prefix', $response['data']['partner_token_prefix'], 'default', 0);

            }
        }

    }

    public  function checkoutCoupon(Varien_Event_Observer $observer)
    {
        if($client = self::init())
        {
            $event = $observer->getEvent();
            $order = $event->getPayment()->getOrder();
            if ($coupon_code = $order->getCouponCode()) {
                if($card = self::getGiftdCard($coupon_code))
                {
                    $client->charge($coupon_code, $card->amount_available, $order->getGrandTotal(), $order->getCustomerId().'_'.$order->getGrandTotal());
                }
            }
        }

    }


    public function processCoupon(Varien_Event_Observer $observer)
    {
        if($_REQUEST['remove'] != 0)
            return;

        $coupon_code = $_REQUEST['coupon_code'];
        if(self::init())
        {
            if($card = self::getGiftdCard($coupon_code))
            {
                $coupon_value = $card->amount_available;
                self::generateRule("Giftd_card",$coupon_code,$coupon_value);
            }
        }
    }

    public function getGiftdCard($the_coupon_code)
    {
        $prefix = Mage::getStoreConfig('giftd_cards/api_settings/partner_token_prefix',Mage::app()->getStore());
        if($this->client && strlen($the_coupon_code) > 0 && strstr($the_coupon_code, $prefix))
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
        $rule = Mage::getModel('salesrule/rule');
        $customer_groups = array(0, 1, 2, 3);
        $rule->setName($name)
          ->setDescription($name)
          ->setFromDate('')
          ->setCouponType(2)
          ->setCouponCode($coupon_code)
          ->setUsesPerCustomer(1)
          ->setUsesPerCoupon(1)
          ->setCustomerGroupIds($customer_groups) //an array of customer grou pids
          ->setIsActive(1)
          ->setConditionsSerialized('')
          ->setActionsSerialized('')
          ->setStopRulesProcessing(0)
          ->setIsAdvanced(1)
          ->setProductIds('')
          ->setSortOrder(0)
          ->setSimpleAction('cart_fixed')
          ->setDiscountAmount($discount)
          ->setDiscountQty(null)
          ->setDiscountStep(0)
          ->setSimpleFreeShipping('0')
          ->setApplyToShipping('0')
          ->setIsRss(0)
          ->setWebsiteIds(array(1));

        try {
          $rule->save();
         } catch (Exception $e) {
        Mage::log($e->getMessage());
         }
      }
    }
}