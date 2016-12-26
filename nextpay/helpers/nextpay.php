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

class nextpayHelpernextpay
{
    public $_method;
    public $cart;
    public $order;
    public $vendor;
    public $customerData;
    public $context;
    public $total;
    public $post_variables;
    public $post_string;
    public $requestData;
    public $response;
    public $currency_code_3;
    public $currency_display;
    public $nextpayPlugin;
    private $_timeout = 60;
    const TIMEOUT_SETEXPRESSCHECKOUT = 15;
    const TIMEOUT_GETEXPRESSCHECKOUTDETAILS = 15;
    const TIMEOUT_OTHERS = 60;

    const FRAUD_FAILURE_ERROR_CODE = 10486;
    const FMF_PENDED_ERROR_CODE = 11610;
    const FMF_DENIED_ERROR_CODE = 11611;
    const BNCODE = 'VirtueMart_Cart_PPA';

    public function __construct($method, $nextpayPlugin)
    {
        $session = JFactory::getSession();
        $this->context = $session->getId();
        $this->_method = $method;
        $this->nextpayPlugin = $nextpayPlugin;
        //Set the vendor
        $vendorModel = VmModel::getModel('Vendor');
        $vendorModel->setId($this->_method->virtuemart_vendor_id);
        $vendor = $vendorModel->getVendor();
        $vendorModel->addImages($vendor, 1);
        $this->vendor = $vendor;

        $this->getnextpayPaymentCurrency();
    }

    public function getnextpayPaymentCurrency($getCurrency = false)
    {
        vmPSPlugin::getPaymentCurrency($this->_method);
        $this->currency_code_3 = shopFunctions::getCurrencyByID($this->_method->payment_currency, 'currency_code_3');
    }

    public function getContext()
    {
        return $this->context;
    }

    public function setCart($cart)
    {
        $this->cart = $cart;
        if (!isset($this->cart->cartPrices) or empty($this->cart->cartPrices)) {
            $this->cart->prepareCartData();
        }
    }

    public function setOrder($order)
    {
        $this->order = $order;
    }

    public function setCustomerData($customerData)
    {
        $this->customerData = $customerData;
    }

    public function loadCustomerData()
    {
        $this->customerData = new nextpayHelperCustomerData();
        $this->customerData->load();
        $this->customerData->loadPost();
    }

    /*
     *  removing  all but alphanumeric characters & spaces.
     */
    public function getItemName($name)
    {
        $name = substr(strip_tags($name), 0, 127);
        $name = preg_replace('/[^a-zA-Z0-9\s]/', '', $name);

        return $name;
    }

    public function getProductAmount($productPricesUnformatted)
    {
        if ($productPricesUnformatted['salesPriceWithDiscount']) {
            return vmPSPlugin::getAmountValueInCurrency($productPricesUnformatted['salesPriceWithDiscount'], $this->_method->payment_currency);
        } else {
            return vmPSPlugin::getAmountValueInCurrency($productPricesUnformatted['salesPrice'], $this->_method->payment_currency);
        }
    }

    public function getProductAmountWithoutTax($productPricesUnformatted)
    {
        if ($productPricesUnformatted['discountedPriceWithoutTax']) {
            return vmPSPlugin::getAmountValueInCurrency($productPricesUnformatted['discountedPriceWithoutTax'], $this->_method->payment_currency);
        } else {
            return vmPSPlugin::getAmountValueInCurrency($productPricesUnformatted['priceBeforeTax'], $this->_method->payment_currency);
        }
    }

    public function getProductTaxAmount($productPricesUnformatted)
    {
        if ($productPricesUnformatted['subtotal_tax_amount']) {
            return vmPSPlugin::getAmountValueInCurrency($productPricesUnformatted['subtotal_tax_amount'], $this->_method->payment_currency);
        }
    }

    public function addRulesBill($rules)
    {
        $handling = 0;
        foreach ($rules as $rule) {
            $handling += vmPSPlugin::getAmountValueInCurrency($this->cart->cartPrices[$rule['virtuemart_calc_id'].'Diff'], $this->_method->payment_currency);
        }

        return $handling;
    }

    /**
     * @return value
     */
    public function getHandlingAmount()
    {
        $handling = 0;
        $handling += $this->addRulesBill($this->cart->cartData['DBTaxRulesBill']);
        $handling += $this->addRulesBill($this->cart->cartData['taxRulesBill']);
        $handling += $this->addRulesBill($this->cart->cartData['DATaxRulesBill']);
        $handling += vmPSPlugin::getAmountValueInCurrency($this->cart->cartPrices['salesPricePayment'], $this->_method->payment_currency);

        return $handling;
    }

    public function setTotal($total)
    {
        if (!class_exists('CurrencyDisplay')) {
            require VMPATH_ADMIN.DS.'helpers'.DS.'currencydisplay.php';
        }
        $this->total = vmPSPlugin::getAmountValueInCurrency($total, $this->_method->payment_currency);

        $cd = CurrencyDisplay::getInstance($this->cart->pricesCurrency);
    }

    public function getTotal()
    {
        return $this->total;
    }

    public function getResponse()
    {
        return $this->response;
    }

    public function getRequest()
    {
        $this->debugLog($this->requestData, 'nextpay '.$this->requestData['METHOD'].' Request variables ', 'debug');

        return $this->requestData;
    }

    protected function sendRequest($post_data)
    {
        $retryCodes = ['401', '403', '404'];

        $this->post_data = $post_data;
        $post_url = $this->_getApiUrl();

        $post_string = $this->ToUri($post_data);
        $curl_request = curl_init($post_url);
        curl_setopt($curl_request, CURLOPT_POSTFIELDS, $post_string);
        curl_setopt($curl_request, CURLOPT_HEADER, 0);
        curl_setopt($curl_request, CURLOPT_TIMEOUT, $this->_timeout);
        curl_setopt($curl_request, CURLOPT_RETURNTRANSFER, 1);

        if ($this->_method->authentication == 'certificate') {
            $certPath = '';
            $passPhrase = '';
            $this->getSSLCertificate($certPath, $passPhrase);
            curl_setopt($curl_request, CURLOPT_SSLCERT, $certPath);
            curl_setopt($curl_request, CURLOPT_SSLCERTPASSWD, $passPhrase);
            curl_setopt($curl_request, CURLOPT_SSL_VERIFYPEER, 1);
            curl_setopt($curl_request, CURLOPT_SSL_VERIFYHOST, 2);
        } else {
            curl_setopt($curl_request, CURLOPT_SSL_VERIFYPEER, false);
        }

        curl_setopt($curl_request, CURLOPT_POST, 1);
        if (preg_match('/xml/', $post_url)) {
            curl_setopt($curl_request, CURLOPT_HTTPHEADER, ['Content-Type: text/xml']);
        }

        $response = curl_exec($curl_request);

        if ($curl_error = curl_error($curl_request)) {
            $this->debugLog($curl_error, '----CURL ERROR----', 'error');
        }
        /*
                $httpStatus = curl_getinfo($curl_request, CURLINFO_HTTP_CODE);
                $retries = 0;
                if(in_array($httpStatus, $retryCodes) && isset($this->retry)) {
                    $this->debugLog("Got $httpStatus response from server. Retrying");

                    do 	{
                        $result = curl_exec(debugLog);
                        $httpStatus = curl_getinfo(debugLog, CURLINFO_HTTP_CODE);

                    } while (in_array($httpStatus, self::$retryCodes) && ++$retries < $this->retry );


                }
                */

        $responseArray = [];
        parse_str($response, $responseArray); // Break the NVP string to an array
        curl_close($curl_request);

        //$responseArray['invoice'] = $this->order['details']['BT']->order_number;
        $responseArray['custom'] = $this->context;
        $responseArray['method'] = $post_data['METHOD'];
        $this->response = $responseArray;

        if ($this->response['ACK'] == 'SuccessWithWarning') {
            $level = 'warning';
        } else {
            $level = 'debug';
        }

        $this->debugLog($post_data, 'nextpay '.$post_data['METHOD'].' Request variables:', $level);
        $this->debugLog($this->response, 'nextpay response:', $level);

        return $this->response;
    }

    /**
     * Get ssl parameters for certificate based client authentication.
     *
     * @param string $certPath - path to client certificate file (PEM formatted file)
     */
    public function getSSLCertificate(&$certifPath, &$passPhrase)
    {
        $safePath = VmConfig::get('forSale_path', '');
        if ($safePath) {
            $sslCertifFolder = $safePath.'nextpay';
        }
        $certifPath = $sslCertifFolder.DS.$this->api_certificate;
    }

    protected function setTimeOut($value = 45)
    {
        $this->_timeout = $value;
    }

    protected function _getnextpayUrl($protocol = 'https://', $includePath = true)
    {
        $url = ($this->_method->sandbox) ? $protocol.'www.sandbox.nextpay.com' : $protocol.'www.nextpay.com';
        if ($includePath) {
            $url .= '/cgi-bin/webscr';
        }

        return $url;
    }

    protected function _getApiUrl()
    {
        $url_auth = '';
        if ($this->_method->authentication == 'signature') {
            $url_auth = '-3t';
        }
        $url_environment = '';
        if ($this->_method->sandbox) {
            $url_environment = '.sandbox';
        }
        //return ($this->_method->sandbox=='sandbox') ? 'https://api-3t.sandbox.nextpay.com/nvp' : 'https://api-3t.nextpay.com/nvp';
        return 'https://api'.$url_auth.$url_environment.'.nextpay.com/nvp';
    }

    protected function getDurationValue($duration)
    {
        $parts = explode('-', $duration);

        return $parts[0];
    }

    protected function getDurationUnit($duration)
    {
        $parts = explode('-', $duration);

        return $parts[1];
    }

    protected function truncate($string, $length)
    {
        return substr($string, 0, $length);
    }

    protected function _getFormattedDate($month, $year)
    {
        return sprintf('%02d%04d', $month, $year);
    }

    public function validate($enqueueMessage = true)
    {
        return true;
    }

    public function validatecheckout($enqueueMessage = true)
    {
        return true;
    }

    public function ToUri($post_variables)
    {
        $poststring = '';
        foreach ($post_variables as $key => $val) {
            $poststring .= urlencode($key).'='.urlencode($val).'&';
        }
        $poststring = rtrim($poststring, '& ');

        return $poststring;
    }

    public function displayExtraPluginInfo()
    {
        $extraInfo = '';
        if ($this->_method->payment_type == '_xclick-auto-billing' && $this->customerData->getVar('autobilling_max_amount')) {
            $cd = CurrencyDisplay::getInstance($this->_method->payment_currency);
            $extraInfo .= '<br/>';
            $extraInfo .= vmText::_('VMPAYMENT_nextpay_PAYMENT_BILLING_MAX_AMOUNT').': '.$cd->priceDisplay($this->customerData->getVar('autobilling_max_amount'));
        }
        if ($this->_method->payment_type == '_xclick-subscriptions') {
            $extraInfo .= '<br /><span class="vmpayment_cardinfo">';
            $extraInfo .= $this->getRecurringProfileDesc();
            $extraInfo .= '</span>';
        }
        if ($this->_method->payment_type == '_xclick-payment-plan') {
            $extraInfo .= '<br /><span class="vmpayment_cardinfo">';
            $extraInfo .= $this->getPaymentPlanDesc();
            $extraInfo .= '</span>';
        }

        return $extraInfo;
    }

    public function getExtraPluginInfo()
    {
        $extraInfo = '';

        return $extraInfo;
    }

    public function getLogoImage()
    {
        if ($this->_method->logoimg) {
            return JURI::base().'/images/stories/virtuemart/payment/'.$this->_method->logoimg;
        } else {
            return JURI::base().$this->vendor->images[0]->file_url;
        }
    }

    public function getRecurringProfileDesc()
    {

//		$recurringDesc = '';
//		if ($this->_method->subcription_trials) {
//			$recurringDesc .= vmText::_('VMPAYMENT_nextpay_TRIAL_PERIODS') . $this->_method->trial1_duration . ': '.$this->_method->trial1_price.'<br />';
//		}
//		$recurringDesc .= vmText::_('VMPAYMENT_nextpay_SUBSCRIPTION_DURATION').': '.$this->_method->subscription_duration . '<br />';
//		$recurringDesc .= vmText::_('VMPAYMENT_nextpay_SUBSCRIPTION_TERM').': '.$this->_method->subscription_term . '<br />';

        $durationValue = $this->getDurationValue($this->_method->subscription_duration);
        $durationUnit = $this->getDurationUnit($this->_method->subscription_duration);
        $recurringDesc = vmText::sprintf('VMPAYMENT_nextpay_SUBSCRIPTION_DESCRIPTION', $durationValue, $durationUnit, $this->_method->subscription_term);

        return $recurringDesc;
    }

    public function getPaymentPlanDesc()
    {

//		$recurringDesc = '';
//		if ($this->_method->subcription_trials) {
//			$recurringDesc .= vmText::_('VMPAYMENT_nextpay_TRIAL_PERIODS') . $this->_method->trial1_duration . ': '.$this->_method->trial1_price.'<br />';
//		}
//		$recurringDesc .= vmText::_('VMPAYMENT_nextpay_SUBSCRIPTION_DURATION').': '.$this->_method->subscription_duration . '<br />';
//		$recurringDesc .= vmText::_('VMPAYMENT_nextpay_SUBSCRIPTION_TERM').': '.$this->_method->subscription_term . '<br />';

        $durationValue = $this->getDurationValue($this->_method->payment_plan_duration);
        $durationUnit = $this->getDurationUnit($this->_method->payment_plan_duration);
        $recurringDesc = vmText::sprintf('VMPAYMENT_nextpay_PAYMENT_PLAN_DESCRIPTION', $this->_method->payment_plan_term, $durationValue, $durationUnit);
        if ($this->_method->payment_plan_defer && $this->_method->nextpayproduct == 'std') {
            $defer_duration = $this->getDurationValue($this->_method->payment_plan_defer_duration);
            $defer_unit = $this->getDurationUnit($this->_method->payment_plan_defer_duration);
            $startDate = JFactory::getDate('+'.$defer_duration.' '.$defer_unit);
            $recurringDesc .= '<br/>'.vmText::sprintf('VMPAYMENT_nextpay_PAYMENT_PLAN_INITIAL_PAYMENT', JHTML::_('date', $startDate->toFormat(), vmText::_('DATE_FORMAT_LC4')));
        } else {
            if ($this->_method->payment_plan_defer_strtotime) {
                $startDate = JFactory::getDate($this->_method->payment_plan_defer_strtotime);
                $recurringDesc .= '<br/>'.vmText::sprintf('VMPAYMENT_nextpay_PAYMENT_PLAN_INITIAL_PAYMENT', JHTML::_('date', $startDate->toFormat(), vmText::_('DATE_FORMAT_LC4')));
                //$recurringDesc .= '<br/>'.vmText::sprintf('VMPAYMENT_nextpay_PAYMENT_PLAN_INITIAL_PAYMENT',date(vmText::_('DATE_FORMAT_LC4'),strtotime('first day of next month')));
            }
        }

        return $recurringDesc;
    }

    /********************************/
    /* Instant Payment Notification */
    /********************************/
    public function processIPN($nextpay_data, $payments)
    {

        // check that the remote IP is from nextpay.
        if (!$this->checknextpayIps($nextpay_data)) {
            return false;
        }
        // Validate the IPN content upon nextpay
        if (!$this->validateIpnContent($nextpay_data)) {
            return false;
        }
        //Check the nextpay response
        /*
         * https://cms.nextpay.com/us/cgi-bin/?cmd=_render-content&content_ID=developer/e_howto_html_IPNandPDTVariables
         * The status of the payment:
         * Canceled_Reversal: A reversal has been canceled. For example, you won a dispute with the customer, and the funds for the transaction that was reversed have been returned to you.
         * Completed: The payment has been completed, and the funds have been added successfully to your account balance.
         * Created: A German ELV payment is made using Express Checkout.
         * Denied: You denied the payment. This happens only if the payment was previously pending because of possible reasons described for the pending_reason variable or the Fraud_Management_Filters_x variable.
         * Expired: This authorization has expired and cannot be captured.
         * Failed: The payment has failed. This happens only if the payment was made from your customer’s bank account.
         * Pending: The payment is pending. See pending_reason for more information.
         * Refunded: You refunded the payment.
         * Reversed: A payment was reversed due to a chargeback or other type of reversal. The funds have been removed from your account balance and returned to the buyer. The reason for the reversal is specified in the ReasonCode element.
         * Processed: A payment has been accepted.
         * Voided: This authorization has been voided.
         */
        $order_history = [];
        $order_history['customer_notified'] = 1;
        if ($nextpay_data['txn_type'] == 'subscr_cancel') {
            $order_history['order_status'] = $this->_method->status_canceled;
        } elseif ($nextpay_data['txn_type'] == 'mp_cancel') {
            $order_history['order_status'] = $this->_method->status_canceled;
        } elseif ($nextpay_data['txn_type'] == 'subscr_eot') {
            $order_history['order_status'] = $this->_method->status_expired;
        } elseif ($nextpay_data['txn_type'] == 'recurring_payment_expired') {
            $order_history['order_status'] = $this->_method->status_expired;
        } elseif ($nextpay_data['txn_type'] == 'subscr_signup') {
            //TODO: Validate the response
            $order_history['order_status'] = $this->_method->status_success;
        } elseif ($nextpay_data['txn_type'] == 'recurring_payment_profile_created') {
            if ($nextpay_data['profile_status'] == 'Active') {
                $order_history['order_status'] = $this->_method->status_success;
            } else {
                $order_history['order_status'] = $this->_method->status_canceled;
            }
        } else {
            if (strcmp($nextpay_data['payment_status'], 'Completed') == 0) {
                $this->debugLog('Completed', 'payment_status', 'debug');

                // 1. check the payment_status is Completed
                // 2. check that txn_id has not been previously processed
                if ($this->_check_txn_id_already_processed($payments, $nextpay_data['txn_id'])) {
                    $this->debugLog($nextpay_data['txn_id'], '_check_txn_id_already_processed', 'debug');

                    return false;
                }
                // 3. check email and amount currency is correct
                if ($nextpay_data['txn_type'] != 'recurring_payment' && !$this->_check_email_amount_currency($payments, $nextpay_data)) {
                    return false;
                }
                // now we can process the payment
                if (strcmp($nextpay_data['payment_status'], 'Authorization') == 0) {
                    $order_history['order_status'] = $this->_method->status_pending;
                } else {
                    $order_history['order_status'] = $this->_method->status_success;
                }
                $order_history['comments'] = vmText::sprintf('VMPAYMENT_nextpay_PAYMENT_STATUS_CONFIRMED', $this->order['details']['BT']->order_number);
            } elseif (strcmp($nextpay_data['payment_status'], 'Pending') == 0) {
                $lang = JFactory::getLanguage();
                $key = 'VMPAYMENT_nextpay_PENDING_REASON_FE_'.strtoupper($nextpay_data['pending_reason']);
                if (!$lang->hasKey($key)) {
                    $key = 'VMPAYMENT_nextpay_PENDING_REASON_FE_DEFAULT';
                }
                $order_history['comments'] = vmText::sprintf('VMPAYMENT_nextpay_PAYMENT_STATUS_PENDING', $this->order['details']['BT']->order_number).vmText::_($key);
                $order_history['order_status'] = $this->_method->status_pending;
            } elseif (strcmp($nextpay_data['payment_status'], 'Refunded') == 0) {
                if ($this->_is_full_refund($payments, $nextpay_data)) {
                    $order_history['comments'] = vmText::sprintf('VMPAYMENT_nextpay_PAYMENT_STATUS_REFUNDED', $this->order['details']['BT']->order_number);
                    $order_history['order_status'] = $this->_method->status_refunded;
                } else {
                    $order_history['comments'] = vmText::sprintf('VMPAYMENT_nextpay_PAYMENT_STATUS_PARTIAL_REFUNDED', $this->order['details']['BT']->order_number);
                    $order_history['order_status'] = isset($this->_method->status_partial_refunded) ? $this->_method->status_partial_refunded : 'R';
                }
            } elseif (isset($nextpay_data['payment_status'])) {
                // voided
                $order_history['order_status'] = $this->_method->status_canceled;
            } else {
                /*
                * a notification was received that concerns one of the payment (since $nextpay_data['invoice'] is found in our table),
                * but the IPN notification has no $nextpay_data['payment_status']
                * We just log the info in the order, and do not change the status, do not notify the customer
                */
                $order_history['comments'] = vmText::_('VMPAYMENT_nextpay_IPN_NOTIFICATION_RECEIVED');
                $order_history['customer_notified'] = 0;
            }
        }

        return $order_history;
    }

    protected function checknextpayIps($nextpay_data)
    {
        /*
                $test_ipn = (array_key_exists('test_ipn', $nextpay_data)) ? $nextpay_data['test_ipn'] : 0;
                if ($test_ipn == 1) {
                    return true;
                }
        */
        $order_number = $nextpay_data['invoice'];

        // Get the list of IP addresses for www.nextpay.com and notify.nextpay.com

        if ($this->_method->sandbox) {
            $nextpay_iplist = gethostbynamel('ipn.sandbox.nextpay.com');
            $nextpay_iplist = (array) $nextpay_iplist;
            $this->debugLog($nextpay_iplist, 'checknextpayIps SANDBOX', 'debug', false);
        } else {
            $nextpay_iplist1 = gethostbynamel('www.nextpay.com');
            $nextpay_iplist2 = gethostbynamel('notify.nextpay.com');
            $nextpay_iplist3 = ['216.113.188.202', '216.113.188.203', '216.113.188.204', '66.211.170.66'];
            $nextpay_iplist = array_merge($nextpay_iplist1, $nextpay_iplist2, $nextpay_iplist3);
// http://forum.virtuemart.net/index.php?topic=115375.msg406664#msg406664

            // Added JH 2013-10-12
            //Current IP addresses
            //------------api.nextpay.com---------
            $nextpay_iplist_api = [
                '173.0.88.66',
                '173.0.88.98',
                '173.0.84.66',
                '173.0.84.98',
                '173.0.80.00',
                '173.0.80.01',
                '173.0.80.02',
                '173.0.80.03',
                '173.0.80.04',
                '173.0.80.05',
                '173.0.80.06',
                '173.0.80.07',
                '173.0.80.08',
                '173.0.80.09',
                '173.0.80.10',
                '173.0.80.11',
                '173.0.80.12',
                '173.0.80.13',
                '173.0.80.14',
                '173.0.80.15',
                '173.0.80.16',
                '173.0.80.17',
                '173.0.80.18',
                '173.0.80.19',
                '173.0.80.20',
                '173.0.82.126',
            ];
            //------------api-aa.nextpay.com------------
            $nextpay_iplist_api_aa = ['173.0.88.67', '173.0.88.99', '173.0.84.99', '173.0.84.67'];
            //'------------api-3t.nextpay.com------------'
            $nextpay_iplist_api_3t_aa = ['173.0.88.69', '173.0.88.101', '173.0.84.69', '173.0.84.101'];
            //------------api-aa-3t.nextpay.com------------
            $nextpay_iplist_api_aa_3t = ['173.0.88.68', '173.0.88.100', '173.0.84.68', '173.0.84.100'];
            //------------notify.nextpay.com (IPN delivery)------------
            $nextpay_iplist_notify = ['173.0.81.1', '173.0.81.33'];
            //'-----------reports.nextpay.com-----------'
            $nextpay_iplist_reports = [
                '66.211.168.93',
                '173.0.84.161',
                '173.0.84.198',
                '173.0.88.161',
                '173.0.88.198',
            ];
            //'------------www.nextpay.com------------'
            //'Starting September 12, 2012 www.nextpay.com will start resolving to a dynamic list of IP addresses and as such should not be whitelisted.'
            //'For more information on IPNs please go here.'
            //'------------ipnpb.nextpay.com------------'
            $nextpay_iplist_ipnb = [
                '64.4.240.0',
                '64.4.240.1',
                '64.4.240.2',
                '64.4.240.3',
                '64.4.240.4',
                '64.4.240.5',
                '64.4.240.6',
                '64.4.240.7',
                '64.4.240.8',
                '64.4.240.9',
                '64.4.240.10',
                '64.4.240.11',
                '64.4.240.12',
                '64.4.240.13',
                '64.4.240.14',
                '64.4.240.15',
                '64.4.240.16',
                '64.4.240.17',
                '64.4.240.18',
                '64.4.240.19',
                '64.4.240.20',
                '118.214.15.186',
                '118.215.103.186',
                '118.215.119.186',
                '118.215.127.186',
                '118.215.15.186',
                '118.215.151.186',
                '118.215.159.186',
                '118.215.167.186',
                '118.215.199.186',
                '118.215.207.186',
                '118.215.215.186',
                '118.215.231.186',
                '118.215.255.186',
                '118.215.39.186',
                '118.215.63.186',
                '118.215.7.186',
                '118.215.79.186',
                '118.215.87.186',
                '118.215.95.186',
                '202.43.63.186',
                '69.192.31.186',
                '72.247.111.186',
                '88.221.43.186',
                '92.122.143.186',
                '92.123.151.186',
                '92.123.159.186',
                '92.123.163.186',
                '92.123.167.186',
                '92.123.179.186',
                '92.123.183.186',
            ];
            // JH

            $nextpay_iplist = array_merge($nextpay_iplist, // Added JH 2013-10-12
                $nextpay_iplist_api, $nextpay_iplist_api_aa, $nextpay_iplist_api_3t_aa, $nextpay_iplist_api_aa_3t, $nextpay_iplist_notify, $nextpay_iplist_ipnb// JH
            );

            $this->debugLog($nextpay_iplist, 'checknextpayIps PRODUCTION', 'debug', false);
        }
        $IP = $this->getRemoteIPAddress();
        $this->debugLog($IP, 'checknextpayIps REMOTE ADDRESS', 'debug', false);

        //  test if the remote IP connected here is a valid IP address
        if (!in_array($IP, $nextpay_iplist)) {
            $text = 'Error with REMOTE IP ADDRESS = '.$IP.".
                        The remote address of the script posting to this notify script does not match a valid nextpay IP address\n
            These are the valid IP Addresses: ".implode(',', $nextpay_iplist).'The Order ID received was: '.$order_number;
            $this->debugLog($text, 'checknextpayIps', 'error', false);

            return false;
        }

        return true;
    }

    /**
     * Get IP address in enviroment with reverse proxy (squid, ngnix, varnish,....)
     * http://forum.virtuemart.net/index.php?topic=124934.msg427325#msg427325.
     *
     * @return mixed
     */
    public function getRemoteIPAddress()
    {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {  //check ip from share internet
            $IP = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {  //to check ip is pass from proxy
            $IP = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $IP = $_SERVER['REMOTE_ADDR'];
        }

        return $IP;
    }

    protected function validateIpnContent($nextpay_data)
    {
        $test_ipn = (array_key_exists('test_ipn', $nextpay_data)) ? $nextpay_data['test_ipn'] : 0;
        if ($test_ipn == 1) {
            //return true;
        }
        $nextpay_data = $_POST;
        // nextpay wants to open the socket in SSL
        $port = 443;
        $nextpay_url = $this->_getnextpayURL('ssl://', false);
        $nextpay_url_header = $this->_getnextpayURL('', false);
        $protocol = 'ssl://';
        /*
         * Before we can trust the contents of the message, we must first verify that the message came from nextpay.
         * To verify the message, we must send back the contents in the exact order they
        *  were received and precede it with the command _notify-validate,
         */

// read the post from nextpay system and add 'cmd'
        $post_msg = 'cmd=_notify-validate';
        if (function_exists('get_magic_quotes_gpc')) {
            $get_magic_quotes_exists = true;
        }
        foreach ($nextpay_data as $key => $value) {
            if ($get_magic_quotes_exists == true && get_magic_quotes_gpc() == 1) {
                $value = str_replace('\r\n', 'QQLINEBREAKQQ', $value);
                $value = urlencode(stripslashes($value));
                $value = str_replace('QQLINEBREAKQQ', "\r\n", $value);
            } else {
                $value = urlencode($value);
            }
            $post_msg .= "&$key=$value";
        }

        $header = "POST /cgi-bin/webscr HTTP/1.1\r\n";
        $header .= 'User-Agent: PHP/'.phpversion()."\r\n";
        $header .= 'Referer: '.$_SERVER['HTTP_HOST'].$_SERVER['PHP_SELF'].@$_SERVER['QUERY_STRING']."\r\n";
        $header .= 'Server: '.$_SERVER['SERVER_SOFTWARE']."\r\n";
        $header .= 'Host: '.$nextpay_url_header.':'.$port."\r\n";
        $header .= "Content-Type: application/x-www-form-urlencoded\r\n";
        $header .= 'Content-Length: '.strlen($post_msg)."\r\n";
        //$header .= "Accept: */*\r\n\r\n";
        $header .= "Connection: close\r\n\r\n";

        $fps = fsockopen($nextpay_url, $port, $errno, $errstr, 30);
        $valid_ipn = false;
        if (!$fps) {
            $this->debugLog(vmText::sprintf('VMPAYMENT_nextpay_ERROR_POSTING_IPN', $errstr, $errno), 'validateIpnContent', 'error', false);
        } else {
            $return = fwrite($fps, $header.$post_msg);
            if ($return === false) {
                $this->debugLog('FALSE', 'validateIpnContent FPUTS', 'error', false);

                return false;
            }
            $res = '';
            while (!feof($fps)) {
                $res .= fgets($fps, 1024);
            }
            fclose($fps);

            // Inspect IPN validation result and act accordingly
            $valid_ipn = strstr($res, 'VERIFIED');
            if (!$valid_ipn) {
                if (strstr($res, 'INVALID')) {
                    $errorInfo = ['nextpay_data' => $nextpay_data, 'post_msg' => $post_msg, 'nextpay_res' => $res];
                    $this->debugLog($errorInfo, vmText::_('VMPAYMENT_nextpay_ERROR_IPN_VALIDATION'), 'error', false);
                } else {
                    $this->debugLog(vmText::_('VMPAYMENT_nextpay_ERROR_IPN_VALIDATION').': NO ANSWER FROM nextpay', 'validateIpnContent', 'error', false);
                }
            }
        }

        $this->debugLog('valid_ipn: '.$valid_ipn, 'validateIpnContent', 'debug', false);

        return $valid_ipn;
    }

    protected function _check_txn_id_already_processed($payments, $txn_id)
    {
        if ($this->order['details']['BT']->order_status == $this->_method->status_success) {
            foreach ($payments as $payment) {
                $nextpay_data = json_decode($payment->nextpay_fullresponse);
                if ($nextpay_data->txn_id == $txn_id) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function _check_email_amount_currency($payments, $nextpay_data)
    {

        /*
         * TODO Not checking yet because config do not have primary email address
        * Primary email address of the payment recipient (that is, the merchant).
        * If the payment is sent to a non-primary email address on your nextpay account,
        * the receiver_email is still your primary email.
        */

        if ($this->_method->nextpayproduct == 'std') {
            if (strcasecmp($nextpay_data['business'], $this->merchant_email) != 0) {
                $errorInfo = ['nextpay_data' => $nextpay_data, 'merchant_email' => $this->merchant_email];
                $this->debugLog($errorInfo, 'IPN notification: wrong merchant_email', 'error', false);

                return false;
            }
        }
        $result = false;
        if ($this->_method->nextpayproduct == 'std' and $nextpay_data['txn_type'] == 'cart') {
            if (abs($payments[0]->payment_order_total - $nextpay_data['mc_gross'] < abs($nextpay_data['mc_gross'] * 0.001)) and ($this->currency_code_3 == $nextpay_data['mc_currency'])) {
                $result = true;
            }
        } else {
            if (($payments[0]->payment_order_total == $nextpay_data['mc_gross']) and ($this->currency_code_3 == $nextpay_data['mc_currency'])) {
                $result = true;
            }
        }
        if (!$result) {
            $errorInfo = [
                'nextpay_data'         => $nextpay_data,
                'payment_order_total'   => $payments[0]->payment_order_total,
                'currency_code_3'       => $this->currency_code_3,
            ];
            $this->debugLog($errorInfo, 'IPN notification with invalid amount or currency or email', 'error', false);
        }

        return $result;
    }

    public static function getnextpayCreditCards()
    {
        return [
            'Visa',
            'Mastercard',
            'Amex',
            'Discover',
            'Maestro',
        ];
    }

    public function _is_full_refund($payment, $nextpay_data)
    {
        if (($payment->payment_order_total == (-1 * $nextpay_data['mc_gross']))) {
            return true;
        } else {
            return false;
        }
    }

    public function RefundTransaction($payment)
    {
        return false;
    }

    public function handleResponse()
    {
        if ($this->response) {
            if ($this->response['ACK'] == 'Failure' || $this->response['ACK'] == 'FailureWithWarning') {
                $error = '';
                $public_error = '';

                for ($i = 0; isset($this->response['L_ERRORCODE'.$i]); $i++) {
                    $error .= $this->response['L_ERRORCODE'.$i];
                    $message = isset($this->response['L_LONGMESSAGE'.$i]) ? $this->response['L_LONGMESSAGE'.$i] : $this->response['L_SHORTMESSAGE'.$i];
                    $error .= ': '.$message.'<br />';
                }
                if ($this->_method->debug) {
                    $public_error = $error;
                }
                $this->debugLog($this->response, 'handleResponse:', 'debug');
                VmError($error, $public_error);

                return false;
            } elseif ($this->response['ACK'] == 'Success' || $this->response['ACK'] == 'SuccessWithWarning' || $this->response['TRANSACTIONID'] != null || $this->response['PAYMENTINFO_0_TRANSACTIONID'] != null) {
                return true;
            } else {
                // Unexpected ACK type. Log response and inform the buyer that the
                // transaction must be manually investigated.
                $error = '';
                $public_error = '';
                $error = 'Unexpected ACK type:'.$this->response['ACK'];
                $this->debugLog($this->response, 'Unexpected ACK type:', 'debug');
                if ($this->_method->debug) {
                    $public_error = $error;
                }
                VmError($error, $public_error);

                return false;
            }
        }
    }

    public function onShowOrderBEPayment($data)
    {
        $showOrderBEFields = $this->getOrderBEFields();
        $prefix = 'nextpay_RESPONSE_';

        $html = '';
        if (isset($data->ACK)) {
            if ($data->ACK == 'SuccessWithWarning' && $data->L_ERRORCODE0 == self::FMF_PENDED_ERROR_CODE && $data->PAYMENTSTATUS == 'Pending'
            ) {
                $showOrderField = 'L_SHORTMESSAGE0';
                $html .= $this->nextpayPlugin->getHtmlRowBE($prefix.$showOrderField, $this->highlight($data->$showOrderField));
            }
            if (($data->ACK == 'Failure' or $data->ACK == 'FailureWithWarning')) {
                $showOrderField = 'L_SHORTMESSAGE0';
                $html .= $this->nextpayPlugin->getHtmlRowBE($prefix.'ERRORMSG', $this->highlight($data->$showOrderField));
                $showOrderField = 'L_LONGMESSAGE0';
                $html .= $this->nextpayPlugin->getHtmlRowBE($prefix.'ERRORMSG', $this->highlight($data->$showOrderField));
            }
        }

        foreach ($showOrderBEFields as $key => $showOrderBEField) {
            if (($showOrderBEField == 'PAYMENTINFO_0_REASONCODE' and isset($data->$showOrderBEField) and $data->$showOrderBEField != 'None') or
                ($showOrderBEField == 'PAYMENTINFO_0_ERRORCODE' and isset($data->$showOrderBEField) and $data->$showOrderBEField != 0)  or
                ($showOrderBEField != 'PAYMENTINFO_0_REASONCODE'  and $showOrderBEField != 'PAYMENTINFO_0_ERRORCODE')
            ) {
                if (isset($data->$showOrderBEField)) {
                    $key = $prefix.$key;
                    $html .= $this->nextpayPlugin->getHtmlRowBE($key, $data->$showOrderBEField);
                }
            }
        }

        return $html;
    }

    public function onShowOrderBEPaymentByFields($payment)
    {
    }

    /*********************/
    /* Log and Reporting */
    /*********************/
    public function debug($subject, $title = '', $echo = true)
    {
        $debug = '<div style="display:block; margin-bottom:5px; border:1px solid red; padding:5px; text-align:left; font-size:10px;white-space:nowrap; overflow:scroll;">';
        $debug .= ($title) ? '<br /><strong>'.$title.':</strong><br />' : '';
        //$debug .= '<pre>';
        $debug .= str_replace('=>', '&#8658;', str_replace('Array', '<font color="red"><b>Array</b></font>', nl2br(str_replace(' ', ' &nbsp; ', print_r($subject, true)))));
        //$debug .= '</pre>';
        $debug .= '</div>';
        if ($echo) {
            echo $debug;
        } else {
            return $debug;
        }
    }

    public function highlight($string)
    {
        return '<span style="color:red;font-weight:bold">'.$string.'</span>';
    }

    public function debugLog($message, $title = '', $type = 'message', $echo = false, $doVmDebug = false)
    {
        $masked_fields = ['ACCT', 'CVV2', 'signature', 'SIGNATURE', 'api_password', 'PWD'];
        //Nerver log the full credit card number nor the CVV code.
        if (is_array($message)) {
            foreach ($masked_fields as $masked_field) {
                if (array_key_exists($masked_field, $message)) {
                    $message[$masked_field] = '**MASKED**';
                }
            }
        }

        if ($this->_method->debug) {
            $this->debug($message, $title, true);
        }

        if ($echo) {
            echo $message.'<br/>';
        }

        $this->nextpayPlugin->debugLog($message, $title, $type, $doVmDebug);
    }
}
