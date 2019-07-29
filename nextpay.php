<?php

defined('_JEXEC') or die('Restricted access');

/*
 * @version $Id: nextpay.php,v 1.4 2005/05/27 19:33:57 ei
 *
 * a special type of 'cash on delivey':
 * @author Max Milbers, Valérie Isaksen
 * @version $Id: nextpay.php 5122 2011-12-18 22:24:49Z alatak $
 * @package VirtueMart
 * @subpackage payment
 * @copyright Copyright (c) 2004 - 2014 VirtueMart Team. All rights reserved.
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL, see LICENSE.php
 * VirtueMart is free software. This version may have been modified pursuant
 * to the GNU General Public License, and as distributed it includes or
 * is derivative of works licensed under the GNU General Public License or
 * other free or open source software licenses.
 * See /administrator/components/com_virtuemart/COPYRIGHT.php for copyright notices and details.
 *
 * http://virtuemart.net
 */
if (!class_exists('vmPSPlugin')) {
    require JPATH_VM_PLUGINS.DS.'vmpsplugin.php';
}

class plgVmPaymentnextpay extends vmPSPlugin
{
    public function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);
        // 		vmdebug('Plugin stuff',$subject, $config);
        $this->_loggable = true;
        $this->tableFields = array_keys($this->getTableSQLFields());
        $this->_tablepkey = 'id';
        $this->_tableId = 'id';
        $varsToPush = $this->getVarsToPush();
        $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
    }

    /**
     * Create the table for this plugin if it does not yet exist.
     *
     * @author Valérie Isaksen
     */
    public function getVmPluginCreateTableSQL()
    {
        return $this->createTableSQL('Payment nextpay Table');
    }

    /**
     * Fields to create the payment table.
     *
     * @return string SQL Fileds
     */
    public function getTableSQLFields()
    {
        $SQLfields = [
            'id'                          => 'int(1) UNSIGNED NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id'         => 'int(1) UNSIGNED',
            'order_number'                => 'char(64)',
            'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED',
            'payment_name'                => 'varchar(5000)',
            'payment_order_total'         => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
            'payment_currency'            => 'char(3)',
            'email_currency'              => 'char(3)',
            'cost_per_transaction'        => 'decimal(10,2)',
            'cost_percent_total'          => 'decimal(10,2)',
            'tax_id'                      => 'smallint(1)',
        ];

        return $SQLfields;
    }

    /**
     * @author Valérie Isaksen
     */
    public function plgVmConfirmedOrder($cart, $order)
    {

        // echo "1"; exit;

        if (!($this->_currentMethod = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
            return; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($this->_currentMethod->payment_element)) {
            return false;
        }

        if (!class_exists('VirtueMartModelOrders')) {
            require VMPATH_ADMIN.DS.'models'.DS.'orders.php';
        }
        if (!class_exists('VirtueMartModelCurrency')) {
            require VMPATH_ADMIN.DS.'models'.DS.'currency.php';
        }

        $params = $this->_currentMethod;

        $new_status = '';

        $usrBT = $order['details']['BT'];
        $address = ((isset($order['details']['ST'])) ? $order['details']['ST'] : $order['details']['BT']);
        $this->getPaymentCurrency($method);
        $q = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="'.$method->payment_currency.'" ';
        $db = &JFactory::getDBO();
        $db->setQuery($q);
        $currency_code_3 = $db->loadResult();

        $paymentCurrency = CurrencyDisplay::getInstance($method->payment_currency);
        $totalInPaymentCurrency = round($paymentCurrency->convertCurrencyTo($method->payment_currency, $order['details']['BT']->order_total, false), 2);
        $cd = CurrencyDisplay::getInstance($cart->pricesCurrency);

        $dbValues['order_number'] = $order['details']['BT']->order_number;
        $dbValues['payment_name'] = $this->renderPluginName($method, $order);
        $dbValues['virtuemart_paymentmethod_id'] = $cart->virtuemart_paymentmethod_id;
        $dbValues['vnpassargad_custom'] = $return_context;
        $dbValues['cost_per_transaction'] = $method->cost_per_transaction;
        $dbValues['cost_percent_total'] = $method->cost_percent_total;
        $dbValues['payment_currency'] = $method->payment_currency;
        $dbValues['payment_order_total'] = $totalInPaymentCurrency;
        $dbValues['tax_id'] = $method->tax_id;
        $this->storePSPluginInternalData($dbValues);
        $mmm = $method->mmm;
        $transid = $order['details']['BT']->order_number;

        $onorder = rand(11111111111111, 999999999999);
        $amount = round($totalInPaymentCurrency); // مبلغ فاكتور
        $CallbackURL = ''.JURI::root().'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&on='.$order['details']['BT']->order_number.'&onorder='.$onorder.'&pm='.$order['details']['BT']->virtuemart_paymentmethod_id.'&cur='.$params->currency;

        $ApiKey = $params->api;  //Required
        if ($params->currency == 'Toman') {
            $Amount = $amount;
        } else {
            $Amount = $amount / 10;
        }
        $Order_ID = $onorder;  // Required

        try {
            $client = new SoapClient('https://api.nextpay.org/gateway/token.wsdl', ['encoding' => 'UTF-8']);
            $result = $client->TokenGenerator([
                'api_key'     => $ApiKey,
                'amount'         => $Amount,
                'order_id'    => $Order_ID,
                'callback_uri'    => $CallbackURL,
            ]);
            $result = $result->TokenGeneratorResult;
        } catch (SoapFault $e) {
            echo '<pre>';
            print_r($e);
            echo '</pre>';
        }

        if(intval($result->code) == -1){
            header('Location: https://api.nextpay.org/gateway/payment/'.$result->trans_id);
        } else {
            echo'ERR: '.$result->code;
        }
    }

    public function plgVmOnPaymentResponseReceived(&$html)
    {

        // the payment itself should send the parameter needed.
        $virtuemart_paymentmethod_id = JRequest::getInt('pm', 0);
        $order_number = JRequest::getVar('on', 0);

        $vendorId = 0;
        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }
        if (!class_exists('VirtueMartCart')) {
            require JPATH_VM_SITE.DS.'helpers'.DS.'cart.php';
        }
        if (!class_exists('shopFunctionsF')) {
            require JPATH_VM_SITE.DS.'helpers'.DS.'shopfunctionsf.php';
        }
        if (!class_exists('VirtueMartModelOrders')) {
            require JPATH_VM_ADMINISTRATOR.DS.'models'.DS.'orders.php';
        }
        $Vmpassargad_data = JRequest::getVar('on');
        $payment_name = $this->renderPluginName($method);
        $virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number);
        if ($virtuemart_order_id) {
            if (!class_exists('VirtueMartCart')) {
                $params = $this->_currentMethod;
            }

            $cart = VirtueMartCart::getCart();
            $ons = $_GET['on'];
            $onsord = $_GET['onorder'];
            $status = $_POST['ResCode'];
            $indate = $_GET['iD'];
            $authority = $_REQUEST['RefId'];

/////////////////////////////////////////////////////
        $db = JFactory::getDBO();
            $query = "select * from `#__virtuemart_orders` where `order_number` = '$ons'";
            $db->setQuery($query);
            $am = $db->loadObject();

            $ApiKey = $method->api;
            $amount = round($am->order_total);
            if ($_GET['cur'] == 'Toman') {
                $Amount = $amount;
            } else {
                $Amount = $amount / 10;
            }
            $Trans_ID = isset($_POST['trans_id'])    ? $_POST['trans_id']    : false ;
            $Order_ID = isset($_POST['order_id'])    ? $_POST['order_id']    : false ;

            if ($Trans_ID && $Order_ID) {
                try {
                    $client = new SoapClient('https://api.nextpay.org/gateway/verify.wsdl', ['encoding' => 'UTF-8']);

                    $result = $client->PaymentVerification([
                    'api_key'     => $ApiKey,
                    'trans_id'      => $Trans_ID,
                    'amount'         => $Amount,
                    'order_id' => $Order_ID,
                    ]);
                    $result = $result->PaymentVerificationResult;
                } catch (SoapFault $e) {
                    echo '<pre>';
                    print_r($e);
                    echo '</pre>';
                }
                if(intval($result->code) == 0){
                    echo '<div style="color:green; font-family:tahoma; direction:rtl; text-align:right">
			پرداخت با موفقیت انجام شد !
			<br /></div>';
                    echo "<br><h4 style='color: green'>".$this->getnextpayError($result->code).'</h4><br>';
                    echo '</h1><br/><h3>شماره پيگيري شما :'.$Order_ID.'شماره ارجاع:'.$Trans_ID;
                    //////
                    $dbcoupon = JFactory::getDBO();
                    $inscoupon = new stdClass();
                    $inscoupon->order_status = 'C';
                    $inscoupon->order_number = "$ons";
                    if ($dbcoupon->updateObject('#__virtuemart_orders', $inscoupon, 'order_number')) {
                        unset($dbcoupon);
                    } else {
                        echo $dbcoupon->stderr();
                    }
                    /////
                    $dbcccwpp = &JFactory::getDBO();
                    $dbcccowpp = "select * from `#__virtuemart_orders` where `order_number` = '$ons' AND `order_status` ='C'";
                    $dbcccwpp->setQuery($dbcccowpp);
                    $dbcccwpp->query();
                    $dbcccowpp = $dbcccwpp->loadobject();
                    $opass = $dbcccowpp->order_pass;
                    $vmid = $dbcccowpp->virtuemart_user_id;
                    $dbcccw = &JFactory::getDBO();
                    $dbcccow = "select * from `#__users` where `id` = '$vmid'";
                    $dbcccw->setQuery($dbcccow);
                    $dbcccw->query();
                    $dbcccow = $dbcccw->loadobject();
                    $refrencess = $Order_ID;
                    $rahgiri = $Trans_ID;
                    $mm = $dbcccow->email;
                    $app = &JFactory::getApplication();
                    $sitename = $app->getCfg('sitename');
                    $subject = ''.$sitename.' - فاکتور خريد';
                    $add = JURI::base().'index.php?option=com_virtuemart&view=orders&layout=details&order_number='.$ons.'&order_pass='.$opass;
                    $body = 'از خريد شما ممنونيم'.'<br />'.'</h1><br/><h3>شماره پيگيري شما :'.$rahgiri.'</h3><br/><h3>شماره ارجاع:'.$refrencess.'</h3>'.'<b>شماره فاکتور'.':</b>'.' '.$ons.'<br/>'.'<a href="'.$add.'">نمايش فاکتور</a>';
                    $to = [$mm];
                    $config = &JFactory::getConfig();
                    $from = [
                    $config->get('mailfrom'),
                    $config->get('fromname'), ];
                    // Invoke JMail Class
                    try {
                        $mailer = JFactory::getMailer();
                        // Set sender array so that my name will show up neatly in your inbox
                        $mailer->setSender($from);
                        // Add a recipient -- this can be a single address (string) or an array of addresses
                        $mailer->addRecipient($to);
                        $mailer->setSubject($subject);
                        $mailer->setBody($body);
                        $mailer->isHTML();
                        $mailer->send();
                    } catch (Exception $e) {
                        echo '<pre>';
                        print_r($e->getMessage());
                        echo '</pre>';
                    }
                    $cart = VirtueMartCart::getCart();
                    $cart->emptyCart();
                } else {
                    echo 'Transation failed. Status:'.$result->Status;
                }
            } else {
                echo 'Transaction canceled by user';
            }
        }
    }

    public function getnextpayError($id)
    {
        $errorCode = [
          0  => 'عمليات با موفقيت انجام شد',
	     -1 => 'منتظر ارسال تراکنش و ادامه پرداخت',
	     -2 => 'پرداخت رد شده توسط کاربر یا بانک',
	     -3 => 'پرداخت در حال انتظار جواب بانک',
	     -4 => 'پرداخت لغو شده است',
	    -20 => 'کد api_key ارسال نشده است',
	    -21 => 'کد trans_id ارسال نشده است',
	    -22 => 'مبلغ ارسال نشده',
	    -23 => 'لینک ارسال نشده',
	    -24 => 'مبلغ صحیح نیست',
	    -25 => 'تراکنش قبلا انجام و قابل ارسال نیست',
	    -26 => 'مقدار توکن ارسال نشده است',
	    -27 => 'شماره سفارش صحیح نیست',
	    -28 => 'مقدار فیلد سفارشی [custom] از نوع json نیست',
	    -29 => 'کد بازگشت مبلغ صحیح نیست',
	    -30 => 'مبلغ کمتر از حداقل پرداختی است',
	    -31 => 'صندوق کاربری موجود نیست',
	    -32 => 'مسیر بازگشت صحیح نیست',
	    -33 => 'کلید مجوز دهی صحیح نیست',
	    -34 => 'کد تراکنش صحیح نیست',
	    -35 => 'ساختار کلید مجوز دهی صحیح نیست',
	    -36 => 'شماره سفارش ارسال نشد است',
	    -37 => 'شماره تراکنش یافت نشد',
	    -38 => 'توکن ارسالی موجود نیست',
	    -39 => 'کلید مجوز دهی موجود نیست',
	    -40 => 'کلید مجوزدهی مسدود شده است',
	    -41 => 'خطا در دریافت پارامتر، شماره شناسایی صحت اعتبار که از بانک ارسال شده موجود نیست',
	    -42 => 'سیستم پرداخت دچار مشکل شده است',
	    -43 => 'درگاه پرداختی برای انجام درخواست یافت نشد',
	    -44 => 'پاسخ دریاف شده از بانک نامعتبر است',
	    -45 => 'سیستم پرداخت غیر فعال است',
	    -46 => 'درخواست نامعتبر',
	    -47 => 'کلید مجوز دهی یافت نشد [حذف شده]',
	    -48 => 'نرخ کمیسیون تعیین نشده است',
	    -49 => 'تراکنش مورد نظر تکراریست',
	    -50 => 'حساب کاربری برای صندوق مالی یافت نشد',
	    -51 => 'شناسه کاربری یافت نشد',
	    -52 => 'حساب کاربری تایید نشده است',
	    -60 => 'ایمیل صحیح نیست',
	    -61 => 'کد ملی صحیح نیست',
	    -62 => 'کد پستی صحیح نیست',
	    -63 => 'آدرس پستی صحیح نیست و یا بیش از ۱۵۰ کارکتر است',
	    -64 => 'توضیحات صحیح نیست و یا بیش از ۱۵۰ کارکتر است',
	    -65 => 'نام و نام خانوادگی صحیح نیست و یا بیش از ۳۵ کاکتر است',
	    -66 => 'تلفن صحیح نیست',
	    -67 => 'نام کاربری صحیح نیست یا بیش از ۳۰ کارکتر است',
	    -68 => 'نام محصول صحیح نیست و یا بیش از ۳۰ کارکتر است',
	    -69 => 'آدرس ارسالی برای بازگشت موفق صحیح نیست و یا بیش از ۱۰۰ کارکتر است',
	    -70 => 'آدرس ارسالی برای بازگشت ناموفق صحیح نیست و یا بیش از ۱۰۰ کارکتر است',
	    -71 => 'موبایل صحیح نیست',
	    -72 => 'بانک پاسخگو نبوده است لطفا با نکست پی تماس بگیرید',
	    -73 => 'مسیر بازگشت دارای خطا میباشد یا بسیار طولانیست',
	    -80 => "تنظیم نشده",
	    -81 => "تنظیم نشده",
	    -82 => 'احراز هویت موبایل برای پرداخت شخصی صحیح نمیباشد.',
	    -83 => "تنظیم نشده",
	    -90 => 'بازگشت مبلغ بدرستی انجام شد',
	    -91 => 'عملیات ناموفق در بازگشت مبلغ',
	    -92 => 'در عملیات بازگشت مبلغ خطا رخ داده است',
	    -93 => 'موجودی صندوق کاربری برای بازگشت مبلغ کافی نیست',
	    -94 => 'کلید بازگشت مبلغ یافت نشد'
        ];

        return $errorCode[$id];
    }

    /*
         * Keep backwards compatibility
         * a new parameter has been added in the xml file
         */
    public function getNewStatus($method)
    {
        if (isset($method->status_pending) && $method->status_pending != '') {
            return $method->status_pending;
        } else {
            return 'P';
        }
    }

    /**
     * Display stored payment data for an order.
     */
    public function plgVmOnShowOrderBEPayment($virtuemart_order_id, $virtuemart_payment_id)
    {
        if (!$this->selectedThisByMethodId($virtuemart_payment_id)) {
            return; // Another method was selected, do nothing
        }

        if (!($paymentTable = $this->getDataByOrderId($virtuemart_order_id))) {
            return;
        }
        VmConfig::loadJLang('com_virtuemart');

        $html = '<table class="adminlist table">'."\n";
        $html .= $this->getHtmlHeaderBE();
        $html .= $this->getHtmlRowBE('COM_VIRTUEMART_PAYMENT_NAME', $paymentTable->payment_name);
        $html .= $this->getHtmlRowBE('nextpay_PAYMENT_TOTAL_CURRENCY', $paymentTable->payment_order_total.' '.$paymentTable->payment_currency);
        if ($paymentTable->email_currency) {
            $html .= $this->getHtmlRowBE('nextpay_EMAIL_CURRENCY', $paymentTable->email_currency);
        }
        $html .= '</table>'."\n";

        return $html;
    }

    /*	function getCosts (VirtueMartCart $cart, $method, $cart_prices) {

            if (preg_match ('/%$/', $method->cost_percent_total)) {
                $cost_percent_total = substr ($method->cost_percent_total, 0, -1);
            } else {
                $cost_percent_total = $method->cost_percent_total;
            }
            return ($method->cost_per_transaction + ($cart_prices['salesPrice'] * $cost_percent_total * 0.01));
        }
    */

    /**
     * Check if the payment conditions are fulfilled for this payment method.
     *
     * @author: Valerie Isaksen
     *
     * @param $cart_prices: cart prices
     * @param $payment
     *
     * @return true: if the conditions are fulfilled, false otherwise
     */
    protected function checkConditions($cart, $method, $cart_prices)
    {
        $this->convert_condition_amount($method);
        $amount = $this->getCartAmount($cart_prices);
        $address = (($cart->ST == 0) ? $cart->BT : $cart->ST);

        //vmdebug('nextpay checkConditions',  $amount, $cart_prices['salesPrice'],  $cart_prices['salesPriceCoupon']);
        $amount_cond = ($amount >= $method->min_amount && $amount <= $method->max_amount
            ||
            ($method->min_amount <= $amount && ($method->max_amount == 0)));
        if (!$amount_cond) {
            return false;
        }
        $countries = [];
        if (!empty($method->countries)) {
            if (!is_array($method->countries)) {
                $countries[0] = $method->countries;
            } else {
                $countries = $method->countries;
            }
        }

        // probably did not gave his BT:ST address
        if (!is_array($address)) {
            $address = [];
            $address['virtuemart_country_id'] = 0;
        }

        if (!isset($address['virtuemart_country_id'])) {
            $address['virtuemart_country_id'] = 0;
        }
        if (count($countries) == 0 || in_array($address['virtuemart_country_id'], $countries)) {
            return true;
        }

        return false;
    }

    /*
* We must reimplement this triggers for joomla 1.7
*/

    /**
     * Create the table for this plugin if it does not yet exist.
     * This functions checks if the called plugin is active one.
     * When yes it is calling the nextpay method to create the tables.
     *
     * @author Valérie Isaksen
     */
    public function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
    {
        return $this->onStoreInstallPluginTable($jplugin_id);
    }

    /**
     * This event is fired after the payment method has been selected. It can be used to store
     * additional payment info in the cart.
     *
     * @author Max Milbers
     * @author Valérie isaksen
     *
     * @param VirtueMartCart $cart: the actual cart
     *
     * @return null if the payment was not selected, true if the data is valid, error message if the data is not vlaid
     */
    public function plgVmOnSelectCheckPayment(VirtueMartCart $cart, &$msg)
    {
        return $this->OnSelectCheck($cart);
    }

    /**
     * plgVmDisplayListFEPayment
     * This event is fired to display the pluginmethods in the cart (edit shipment/payment) for exampel.
     *
     * @param object $cart     Cart object
     * @param int    $selected ID of the method selected
     *
     * @return bool True on succes, false on failures, null when this plugin was not selected.
     *              On errors, JError::raiseWarning (or JError::raiseError) must be used to set a message.
     *
     * @author Valerie Isaksen
     * @author Max Milbers
     */
    public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected, &$htmlIn)
    {
        return $this->displayListFE($cart, $selected, $htmlIn);
    }

    /*
* plgVmonSelectedCalculatePricePayment
* Calculate the price (value, tax_id) of the selected method
* It is called by the calculator
* This function does NOT to be reimplemented. If not reimplemented, then the default values from this function are taken.
* @author Valerie Isaksen
* @cart: VirtueMartCart the current cart
* @cart_prices: array the new cart prices
* @return null if the method was not selected, false if the shiiping rate is not valid any more, true otherwise
*
*
*/

    public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name)
    {
        return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    public function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId)
    {
        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }
        $this->getPaymentCurrency($method);

        $paymentCurrencyId = $method->payment_currency;
    }

    /**
     * plgVmOnCheckAutomaticSelectedPayment
     * Checks how many plugins are available. If only one, the user will not have the choice. Enter edit_xxx page
     * The plugin must check first if it is the correct type.
     *
     * @author Valerie Isaksen
     *
     * @param VirtueMartCart cart: the cart object
     *
     * @return null if no plugin was found, 0 if more then one plugin was found,  virtuemart_xxx_id if only one plugin is found
     */
    public function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices, &$paymentCounter)
    {
        return $this->onCheckAutomaticSelected($cart, $cart_prices, $paymentCounter);
    }

    /**
     * This method is fired when showing the order details in the frontend.
     * It displays the method-specific data.
     *
     * @param int $order_id The order ID
     *
     * @return mixed Null for methods that aren't active, text (HTML) otherwise
     *
     * @author Max Milbers
     * @author Valerie Isaksen
     */
    public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name)
    {
        $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }

    /**
     * @param $orderDetails
     * @param $data
     *
     * @return null
     */
    public function plgVmOnUserInvoice($orderDetails, &$data)
    {
        if (!($method = $this->getVmPluginMethod($orderDetails['virtuemart_paymentmethod_id']))) {
            return; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return;
        }
        //vmdebug('plgVmOnUserInvoice',$orderDetails, $method);

        if (!isset($method->send_invoice_on_order_null) || $method->send_invoice_on_order_null == 1 || $orderDetails['order_total'] > 0.00) {
            return;
        }

        if ($orderDetails['order_salesPrice'] == 0.00) {
            $data['invoice_number'] = 'reservedByPayment_'.$orderDetails['order_number']; // Nerver send the invoice via email
        }
    }

    /**
     * @param $virtuemart_paymentmethod_id
     * @param $paymentCurrencyId
     *
     * @return bool|null
     */
    public function plgVmgetEmailCurrency($virtuemart_paymentmethod_id, $virtuemart_order_id, &$emailCurrencyId)
    {
        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }
        if (!($payments = $this->getDatasByOrderId($virtuemart_order_id))) {
            // JError::raiseWarning(500, $db->getErrorMsg());
            return '';
        }
        if (empty($payments[0]->email_currency)) {
            $vendorId = 1; //VirtueMartModelVendor::getLoggedVendor();
            $db = JFactory::getDBO();
            $q = 'SELECT   `vendor_currency` FROM `#__virtuemart_vendors` WHERE `virtuemart_vendor_id`='.$vendorId;
            $db->setQuery($q);
            $emailCurrencyId = $db->loadResult();
        } else {
            $emailCurrencyId = $payments[0]->email_currency;
        }
    }

    /**
     * This event is fired during the checkout process. It can be used to validate the
     * method data as entered by the user.
     *
     * @return bool True when the data was valid, false otherwise. If the plugin is not activated, it should return null.
     *
     * @author Max Milbers

     */

    /**
     * This method is fired when showing when priting an Order
     * It displays the the payment method-specific data.
     *
     * @param int $_virtuemart_order_id The order ID
     * @param int $method_id            method used for this order
     *
     * @return mixed Null when for payment methods that were not selected, text (HTML) otherwise
     *
     * @author Valerie Isaksen
     */
    public function plgVmonShowOrderPrintPayment($order_number, $method_id)
    {
        return $this->onShowOrderPrint($order_number, $method_id);
    }

    public function plgVmDeclarePluginParamsPaymentVM3(&$data)
    {
        return $this->declarePluginParams('payment', $data);
    }

    public function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
    {
        return $this->setOnTablePluginParams($name, $id, $table);
    }
}

// No closing tag
