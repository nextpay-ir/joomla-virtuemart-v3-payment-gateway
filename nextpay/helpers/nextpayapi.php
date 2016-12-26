<?php
/**
 * nextpay payment plugin.
 *
 * @author Jeremy Magne
 * @author Valérie Isaksen
 *
 * @version $Id: nextpay.php 7217 2013-09-18 13:42:54Z alatak $
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL, see LICENSE.php
 * VirtueMart is free software. This version may have been modified pursuant
 * to the GNU General Public License, and as distributed it includes or
 * is derivative of works licensed under the GNU General Public License or
 * other free or open source software licenses.
 * See /administrator/components/com_virtuemart/COPYRIGHT.php for copyright notices and details.
 *
 * http://virtuemart.net
 */
defined('_JEXEC') or die('Restricted access');


class nextpayHelpernextpayApi extends nextpayHelpernextpay
{
    public $api_login_id = '';
    public $api_signature = '';
    public $api_password = '';

    public function __construct($method, $nextpayPlugin)
    {
        parent::__construct($method, $nextpayPlugin);

        //Set the credentials
        if ($this->_method->sandbox) {
            $this->api_login_id = $this->_method->sandbox_api_login_id;
            $this->api_signature = $this->_method->sandbox_api_signature;
            $this->api_password = $this->_method->sandbox_api_password;
        } else {
            $this->api_login_id = $this->_method->api_login_id;
            $this->api_signature = $this->_method->api_signature;
            $this->api_password = $this->_method->api_password;
        }

        if (empty($this->api_login_id) || empty($this->api_signature) || empty($this->api_password)) {
            $text = vmText::sprintf('VMPAYMENT_nextpay_CREDENTIALS_NOT_SET', $this->_method->payment_name, $this->_method->virtuemart_paymentmethod_id);
            vmError($text, $text);
        }
    }

    public function initPostVariables($nextpayMethod)
    {
        $post_variables = [];
        $post_variables['METHOD'] = $nextpayMethod;
        //$post_variables['version']	 	= "106.0"; //https://developer.nextpay.com/webapps/developer/docs/classic/release-notes/
        $post_variables['version'] = '104.0';
        $post_variables['USER'] = $this->api_login_id;
        $post_variables['PWD'] = $this->api_password;
        $post_variables['SIGNATURE'] = $this->api_signature;
        $post_variables['BUTTONSOURCE'] = self::BNCODE;
        $post_variables['CURRENCYCODE'] = $this->currency_code_3;

        if (is_array($this->order) && is_object($this->order['details']['BT'])) {
            $post_variables['INVNUM'] = $this->order['details']['BT']->order_number;
        } else {
            if (is_object($this->order)) {
                $post_variables['INVNUM'] = $this->order->order_number;
            }
        }
        //$post_variables['IPADDRESS'] = ($_SERVER['REMOTE_ADDR'] == '::1') ? '127.0.0.1' : $_SERVER['REMOTE_ADDR'];
        $IP = $this->getRemoteIPAddress();

        $post_variables['IPADDRESS'] = ($IP == '::1') ? '127.0.0.1' : $IP;

        return $post_variables;
    }

    public function addBillTo(&$post_variables)
    {
        $addressBT = $this->order['details']['BT'];

        //Bill To
        $post_variables['FIRSTNAME'] = isset($addressBT->first_name) ? $this->truncate($addressBT->first_name, 50) : '';
        $post_variables['LASTNAME'] = isset($addressBT->last_name) ? $this->truncate($addressBT->last_name, 50) : '';
        $post_variables['STREET'] = isset($addressBT->address_1) ? $this->truncate($addressBT->address_1, 60) : '';
        $post_variables['CITY'] = isset($addressBT->city) ? $this->truncate($addressBT->city, 40) : '';
        $post_variables['ZIP'] = isset($addressBT->zip) ? $this->truncate($addressBT->zip, 40) : '';
        $post_variables['STATE'] = isset($addressBT->virtuemart_state_id) ? ShopFunctions::getStateByID($addressBT->virtuemart_state_id, 'state_2_code') : '';
        $post_variables['COUNTRYCODE'] = ShopFunctions::getCountryByID($addressBT->virtuemart_country_id, 'country_2_code');
    }

    public function addShipTo(&$post_variables)
    {
        $addressST = ((isset($this->order['details']['ST'])) ? $this->order['details']['ST'] : $this->order['details']['BT']);

        //Ship To
        $post_variables['SHIPTONAME'] = (isset($addressST->first_name) || isset($addressST->last_name)) ? $this->truncate($addressST->first_name.' '.$addressST->last_name, 50) : '';
        $post_variables['SHIPTOSTREET'] = isset($addressST->address_1) ? $this->truncate($addressST->address_1, 60) : '';
        $post_variables['SHIPTOCITY'] = isset($addressST->city) ? $this->truncate($addressST->city, 40) : '';
        $post_variables['SHIPTOZIP'] = isset($addressST->zip) ? $this->truncate($addressST->zip, 40) : '';
        $post_variables['SHIPTOSTATE'] = isset($addressST->virtuemart_state_id) ? ShopFunctions::getStateByID($addressST->virtuemart_state_id, 'state_2_code') : '';
        $post_variables['SHIPTOCOUNTRYCODE'] = ShopFunctions::getCountryByID($addressST->virtuemart_country_id, 'country_2_code');
    }

    public function addCreditCard(&$post_variables)
    {
        $post_variables['ACCT'] = $this->customerData->getVar('cc_number');
        $post_variables['CVV2'] = $this->customerData->getVar('cc_cvv');
        $post_variables['CREDITCARDTYPE'] = $this->customerData->getVar('cc_type');
        $post_variables['EXPDATE'] = $this->_getFormattedDate($this->customerData->getVar('cc_expire_month'), $this->customerData->getVar('cc_expire_year'));
    }

    public function ManageCheckout()
    {
        switch ($this->_method->payment_type) {
            case '_xclick':
                return $this->DoPayment();
            case '_xclick-subscriptions':
                return $this->CreateRecurringPaymentsProfile();
            case '_xclick-payment-plan':
                return $this->CreatePaymentPlanProfile();
        }
    }

    public function ManageCancelOrder($payment)
    {
        $this->RefundTransaction($payment);
        /*
        switch ($this->_method->payment_type) {
            case '_xclick':
                return $this->RefundTransaction($payment);
            case '_xclick-subscriptions':
            case '_xclick-payment-plan':
                return $this->ManageRecurringPaymentsProfileStatus($payment);
        }
        */
    }

    public function DoPayment()
    {
        $post_variables = $this->initPostVariables('DoDirectPayment');

        $this->addBillTo($post_variables);
        $this->addShipTo($post_variables);
        $this->addCreditCard($post_variables);

        $post_variables['PAYMENTACTION'] = $this->_method->payment_action;
        $post_variables['AMT'] = $this->total;
        if (isset($this->_method->add_prices_api) and $this->_method->add_prices_api) {
            $this->addPrices($post_variables);
        }

        $this->sendRequest($post_variables);
        if ($this->handleResponse()) {
            if ($this->_method->payment_action == 'Authorization') {
                $this->response['PAYMENTSTATUS'] = 'Pending';
                $this->response['PENDINGREASON'] = 'authorization';
            } else {
                $this->response['PAYMENTSTATUS'] = 'Completed';
                $this->response['PENDINGREASON'] = 'None';
            }
            $this->response['nextpay_response_txn_type'] = 'DoDirectPayment';

            return true;
        } else {
            return false;
        }
    }

    /**
     * @param $post_variables
     */
    public function addPrices(&$post_variables)
    {
        $paymentCurrency = CurrencyDisplay::getInstance($this->_method->payment_currency);
        $i = 1;
        $taxAmount = 0;
        $ITEMAMT = 0;
        $TAXAMT = 0;
        $lastId = 0;
        // Product prices
        if ($this->cart->products) {
            foreach ($this->cart->products as $key => $product) {
                $post_variables['L_NAME'.$i] = $this->getItemName($product->product_name);
                if ($product->product_sku) {
                    $post_variables['L_NUMBER'.$i] = $product->product_sku;
                }
                $post_variables['L_AMT'.$i] = $this->getProductAmountWithoutTax($this->cart->cartPrices[$key]);
                $post_variables['L_QTY'.$i] = $product->quantity;
                $post_variables['L_TAXAMT'.$i] = $this->getProductTaxAmount($this->cart->cartPrices[$key]); // Item sales tax
                $taxAmount += $post_variables['L_TAXAMT'.$i];
                $ITEMAMT += $post_variables['L_AMT'.$i] * $post_variables['L_QTY'.$i];
                $TAXAMT += $post_variables['L_TAXAMT'.$i];
                $lastId = $i;
                $i++;
            }
        }

        // Handling Coupon (handling must be positive value, add then coupon as a product with negative value
        if (!empty($this->cart->cartPrices['salesPriceCoupon'])) {
            $post_variables['L_NAME'.$i] = vmText::_('COM_VIRTUEMART_COUPON_DISCOUNT').': '.$this->cart->couponCode;
            $post_variables['L_AMT'.$i] = vmPSPlugin::getAmountValueInCurrency($this->cart->cartPrices['salesPriceCoupon'], $this->_method->payment_currency);
            $post_variables['L_QTY'.$i] = 1;
            $ITEMAMT += $post_variables['L_AMT'.$i] * $post_variables['L_QTY'.$i];
            //$TAXAMT +=$post_variables["L_TAXAMT" . $i];
        }

        if ($this->cart->cartPrices['paymentValue']) {
            $paymentValue = vmPSPlugin::getAmountValueInCurrency($this->cart->cartPrices['paymentValue'], $this->_method->payment_currency);
            $post_variables['L_NAME'.$i] = vmText::_('COM_VIRTUEMART_PAYMENT');
            $post_variables['L_AMT'.$i] = $paymentValue;
            $post_variables['L_TAXAMT'.$i] = vmPSPlugin::getAmountValueInCurrency($this->cart->cartPrices['paymentTax'], $this->_method->payment_currency); // Item sales tax
            $post_variables['L_QTY'.$i] = 1;
            $ITEMAMT += $post_variables['L_AMT'.$i] * $post_variables['L_QTY'.$i];
            $TAXAMT += $post_variables['L_TAXAMT'.$i];
            $lastId = $i;
        }

// shipment value must include tax
        $shipmentValue = vmPSPlugin::getAmountValueInCurrency($this->cart->cartPrices['salesPriceShipment'], $this->_method->payment_currency);
        if ($shipmentValue >= 0) {
            $post_variables['SHIPPINGAMT'] = $shipmentValue; // Total shipping costs for this order.
        } else {
            $post_variables['SHIPDISCAMT'] = $shipmentValue; // Shipping discount for this order, specified as a negative number.
        }

        $handling = $this->getHandlingAmount();

        $post_variables['HANDLINGAMT'] = $handling;

        $post_variables['CURRENCYCODE'] = $this->currency_code_3;
        $post_variables['AMT'] = $this->total;
        $post_variables['TAXAMT'] = $TAXAMT; // Sum of tax for all items in this order.
        //$post_variables['ITEMAMT'] = vmPSPlugin::getAmountValueInCurrency($this->cart->cartPrices['priceWithoutTax'], $this->_method->payment_currency);
        $post_variables['ITEMAMT'] = $ITEMAMT;

        $pricesCurrency = CurrencyDisplay::getInstance($this->cart->pricesCurrency);
    }

    public function CreateRecurringPaymentsProfile()
    {
        //https://developer.nextpay.com/webapps/developer/docs/classic/direct-payment/ht_dp-recurringPaymentProfile-curl-etc/
        //https://developer.nextpay.com/webapps/developer/docs/classic/api/merchant/CreateRecurringPaymentsProfile_API_Operation_NVP/

        $post_variables = $this->initPostVariables('CreateRecurringPaymentsProfile');
        $this->addBillTo($post_variables);
        $this->addShipTo($post_variables);
        $this->addCreditCard($post_variables);

        //$post_variables['SUBSCRIBERNAME']	= isset($addressBT->first_name) ? $this->truncate($addressBT->first_name, 50) : '';
        $post_variables['PROFILEREFERENCE'] = $this->order['details']['BT']->order_number;
        $post_variables['DESC'] = $this->getRecurringProfileDesc();

        $startDate = JFactory::getDate();
        $post_variables['PROFILESTARTDATE'] = $startDate->toISO8601();
        $post_variables['AUTOBILLOUTAMT'] = 'AddToNextBilling';

        $post_variables['BILLINGFREQUENCY'] = $this->getDurationValue($this->_method->subscription_duration);
        $post_variables['BILLINGPERIOD'] = $this->getDurationUnit($this->_method->subscription_duration);
        $post_variables['TOTALBILLINGCYCLES'] = $this->_method->subscription_term;

        if ($this->cart->cartPrices['salesPricePayment'] && $this->cart->cartPrices['salesPricePayment'] > 0) {
            $post_variables['INITAMT'] = $this->cart->cartPrices['salesPricePayment'];
            $post_variables['FAILEDINITAMTACTION'] = 'CancelOnFailure';
            $post_variables['AMT'] = $this->total - $this->cart->cartPrices['salesPricePayment'];
        } else {
            $post_variables['AMT'] = $this->total;
        }

        if ($this->_method->subcription_trials) {
            $post_variables['TRIALBILLINGFREQUENCY'] = $this->getDurationValue($this->_method->trial1_duration);
            $post_variables['TRIALBILLINGPERIOD'] = $this->getDurationUnit($this->_method->trial1_duration);
            $post_variables['TRIALTOTALBILLINGCYCLES'] = $this->_method->subcription_trials;
            $post_variables['TRIALAMT'] = ($this->_method->trial1_price) ? $this->_method->trial1_price : 0;
        }

        $this->sendRequest($post_variables);

        return $this->handleResponse();
    }

    public function CreatePaymentPlanProfile()
    {
        //Payment plans are not implemented in the API.
        //A workaround is to create a subscription profile and divide the total amount by the term.

        $post_variables = $this->initPostVariables('CreateRecurringPaymentsProfile');
        $this->addBillTo($post_variables);
        $this->addShipTo($post_variables);
        $this->addCreditCard($post_variables);

        //$post_variables['SUBSCRIBERNAME']	= isset($addressBT->first_name) ? $this->truncate($addressBT->first_name, 50) : '';
        $post_variables['PROFILEREFERENCE'] = $this->order['details']['BT']->order_number;
        $post_variables['DESC'] = $this->order['details']['BT']->order_number.': '.$this->getPaymentPlanDesc();

        if ($this->cart->cartPrices['salesPricePayment'] && $this->cart->cartPrices['salesPricePayment'] > 0) {
            $initAmount = $this->cart->cartPrices['salesPricePayment'];
        } else {
            $initAmount = 0;
        }
        $occurenceAmount = round(($this->total - $initAmount) / $this->_method->payment_plan_term, 2);
        if ($this->_method->payment_plan_defer == 2) {
            $initAmount += $occurenceAmount;
            $occurencesCount = $this->_method->payment_plan_term - 1;
        } else {
            $occurencesCount = $this->_method->payment_plan_term;
        }

        if ($this->_method->payment_plan_defer && $this->_method->payment_plan_defer_strtotime) {
            $startDate = JFactory::getDate($this->_method->payment_plan_defer_strtotime);
        } else {
            $startDate = JFactory::getDate();
        }
        $post_variables['PROFILESTARTDATE'] = $startDate->toISO8601();
        $post_variables['AUTOBILLOUTAMT'] = 'AddToNextBilling';

        $post_variables['BILLINGFREQUENCY'] = $this->getDurationValue($this->_method->payment_plan_duration);
        $post_variables['BILLINGPERIOD'] = $this->getDurationUnit($this->_method->payment_plan_duration);
        $post_variables['TOTALBILLINGCYCLES'] = $occurenceAmount;

        if ($this->cart->cartPrices['salesPricePayment'] && $this->cart->cartPrices['salesPricePayment'] > 0) {
            $post_variables['INITAMT'] = $initAmount;
            $post_variables['FAILEDINITAMTACTION'] = 'CancelOnFailure';
        }
        $post_variables['AMT'] = $occurenceAmount;

        $this->sendRequest($post_variables);

        return $this->handleResponse();
    }

    public function GetRecurringPaymentsProfileDetails($profileId)
    {
        $post_variables = $this->initPostVariables('GetRecurringPaymentsProfileDetails');
        $post_variables['PROFILEID'] = $profileId;

        $this->sendRequest($post_variables);

        return $this->handleResponse();
    }

    public function ManageRecurringPaymentsProfileStatus($payment)
    {
        $nextpay_data = json_decode($payment->nextpay_fullresponse);
        $post_variables = $this->initPostVariables('ManageRecurringPaymentsProfileStatus');
        $post_variables['PROFILEID'] = $nextpay_data->PROFILEID;
        $post_variables['ACTION'] = 'Cancel';

        $this->sendRequest($post_variables);
        $this->handleResponse();

        return $this->GetRecurringPaymentsProfileDetails($nextpay_data->PROFILEID);
    }

    public function DoCapture($payment)
    {
        $nextpay_data = json_decode($payment->nextpay_fullresponse);
        //Only capture payment if it still pending
        if (strcasecmp($nextpay_data->PAYMENTSTATUS, 'Pending') != 0 && strcasecmp($nextpay_data->PENDINGREASON, 'authorization') != 0) {
            return false;
        }

        $post_variables = $this->initPostVariables('DoCapture');

        //Do we need to reauthorize ?
        $reauth = $this->doReauthorize($nextpay_data->txn_id, $nextpay_data);
        if ($reauth === false) {
            $post_variables['AuthorizationID'] = $nextpay_data->TRANSACTIONID;
        } else {
            $post_variables['AuthorizationID'] = $reauth;
        }

        $post_variables['PAYMENTACTION'] = 'DoCapture';
        $post_variables['AMT'] = $this->total;
        $post_variables['COMPLETETYPE'] = 'Complete';

        $this->sendRequest($post_variables);
        //print_a($post_variables);
        //print_a($this->response);
        $success = $this->handleResponse();
        if (!$success) {
            $this->doVoid($payment);
        }

        return $success;
    }

    public function doReauthorize($AuthorizationID, $nextpay_data)
    {
        return false;
        $post_variables = $this->initPostVariables('DoReauthorization');
        $post_variables['AuthorizationID'] = $AuthorizationID;
        $post_variables['PAYMENTACTION'] = 'DoReauthorization';
        $post_variables['AMT'] = $this->total;

        $this->sendRequest($post_variables);
        if ($this->handleResponse()) {
            return $this->response['AUTHORIZATIONID'];
        } else {
            return false;
        }
    }

    public function RefundTransaction($payment)
    {
        $nextpay_data = json_decode($payment->nextpay_fullresponse);
        if ($nextpay_data->PAYMENTSTATUS == 'Completed') {
            $post_variables = $this->initPostVariables('RefundTransaction');
            $post_variables['REFUNDTYPE'] = 'Full';
        } else {
            if ($nextpay_data->PAYMENTSTATUS == 'Pending' && $nextpay_data->PENDINGREASON == 'authorization') {
                $post_variables = $this->initPostVariables('DoVoid');
            } else {
                vmInfo('VMPAYMENT_nextpay_CANNOT_REFUND');

                return false;
            }
        }

        $post_variables['TRANSACTIONID'] = $nextpay_data->TRANSACTIONID;

        $this->sendRequest($post_variables);

        return $this->handleResponse();
    }

    public function doVoid($payment)
    {
        $nextpay_data = json_decode($payment->nextpay_fullresponse);
        $post_variables = $this->initPostVariables('DoVoid');
        $post_variables['AuthorizationID'] = $nextpay_data->TRANSACTIONID;
        $this->sendRequest($post_variables);

        return $this->handleResponse();
    }

    public function validate($enqueueMessage = true)
    {
        if (!class_exists('Creditcard')) {
            require VMPATH_ADMIN.DS.'helpers'.DS.'creditcard.php';
        }
        $html = '';
        $cc_valid = true;
        $errormessages = [];

        $cc_type = $this->customerData->getVar('cc_type');
        $cc_number = $this->customerData->getVar('cc_number');
        $cc_cvv = $this->customerData->getVar('cc_cvv');
        $cc_expire_month = $this->customerData->getVar('cc_expire_month');
        $cc_expire_year = $this->customerData->getVar('cc_expire_year');

        if (!Creditcard::validate_credit_card_number($cc_type, $cc_number)) {
            $errormessages[] = 'VMPAYMENT_nextpay_CC_CARD_NUMBER_INVALID';
            $cc_valid = false;
        }
        if ($this->_method->cvv_required or $cc_type == 'Maestro') {
            $required = true;
        } else {
            $required = false;
        }
        if (!Creditcard::validate_credit_card_cvv($cc_type, $cc_cvv, $required)) {
            $errormessages[] = 'VMPAYMENT_nextpay_CC_CARD_CVV_INVALID';
            $cc_valid = false;
        }
        if (!Creditcard::validate_credit_card_date($cc_type, $cc_expire_month, $cc_expire_year)) {
            $errormessages[] = 'VMPAYMENT_nextpay_CC_CARD_DATE_INVALID';
            $cc_valid = false;
        }
        if (!$cc_valid) {
            foreach ($errormessages as $msg) {
                $html .= vmText::_($msg).'<br/>';
            }
        }
        if (!$cc_valid && $enqueueMessage) {
            $app = &JFactory::getApplication();
            $app->enqueueMessage($html, 'error');
        }
        $displayInfoMsg = '';
        if (!$cc_valid) {
            $displayInfoMsg = false;

            return false;
        } else {
            return parent::validate($displayInfoMsg);
        }
    }

    public function displayExtraPluginInfo()
    {
        $extraInfo = '';
        //if ($this->customerData->getVar('cc_number') && $this->validate()) {
        if ($this->customerData->getVar('cc_number')) {
            $cc_number = '**** **** **** '.substr($this->customerData->getVar('cc_number'), -4);
            $creditCardInfos = '<br /><span class="vmpayment_cardinfo">'.vmText::_('VMPAYMENT_nextpay_CC_CCTYPE').$this->customerData->getVar('cc_type').'<br />';
            $creditCardInfos .= vmText::_('VMPAYMENT_nextpay_CC_CCNUM').$cc_number.'<br />';
            $creditCardInfos .= vmText::_('VMPAYMENT_nextpay_CC_CVV2').'****'.'<br />';
            $creditCardInfos .= vmText::_('VMPAYMENT_nextpay_CC_EXDATE').$this->customerData->getVar('cc_expire_month').'/'.$this->customerData->getVar('cc_expire_year');
            $creditCardInfos .= '</span>';
            $extraInfo .= $creditCardInfos;
        } else {
            //$extraInfo .= '<br/><a href="' . JRoute::_('index.php?option=com_virtuemart&view=cart&task=editpayment&Itemid=' . vRequest::getInt('Itemid'), false) . '">' . vmText::_('VMPAYMENT_nextpay_CC_ENTER_INFO') . '</a>';
        }
        $extraInfo .= parent::getExtraPluginInfo();

        return $extraInfo;
    }

    protected function getDurationUnit($duration)
    {
        $parts = explode('-', $duration);
        switch ($parts[1]) {
            case 'D':
                return 'Day';
            case 'W':
                return 'Week';
            case 'M':
                return 'Month';
            case 'Y':
                return 'Year';
        }
    }

    public function getOrderBEFields()
    {
        $showOrderBEFields = [
            'method'                 => 'method',
            'ACK'                    => 'ACK',
            'TXN_ID'                 => 'TRANSACTIONID',
            'PROFILEID'              => 'PROFILEID',
            'MC_GROSS'               => 'PAYMENTINFO_0_AMT',
            'MC_FEE'                 => 'PAYMENTINFO_0_FEEAMT',
            'TAXAMT'                 => 'PAYMENTINFO_0_TAXAMT',
            'MC_CURRENCY'            => 'PAYMENTINFO_0_CURRENCYCODE',
            'PAYMENT_STATUS'         => 'PAYMENTSTATUS',
            'REFUND_STATUS'          => 'REFUNDSTATUS',
            'PENDING_REASON'         => 'PENDINGREASON',
            'REASONCODE'             => 'PAYMENTINFO_0_REASONCODE',
            'ERRORCODE'              => 'PAYMENTINFO_0_ERRORCODE',
            'PROTECTION_ELIGIBILITY' => 'PAYMENTINFO_0_PROTECTIONELIGIBILITY',
            'CORRELATIONID'          => 'CORRELATIONID',

        ];

        return $showOrderBEFields;
    }
}
