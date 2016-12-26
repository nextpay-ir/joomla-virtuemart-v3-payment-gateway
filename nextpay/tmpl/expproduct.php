<?php
/**
 * nextpay payment plugin.
 *
 * @author Valerie Isaksen
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
 ?>

<?php
if ($viewData['sandbox']) {
    ?>
	<span style="color:red;font-weight:bold">Sandbox (<?php echo $viewData['virtuemart_paymentmethod_id'] ?>)</span>
<?php

}

$img = '<img id="nextpayLogo" alt="'.$viewData['text'].'" src="'.$viewData['img'].'"/>';
echo shopFunctionsF::vmPopupLink($viewData['link'], $img, 640, 480, '_blank', $viewData['text']);
