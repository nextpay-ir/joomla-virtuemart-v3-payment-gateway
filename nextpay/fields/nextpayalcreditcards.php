<?php
/**
 * nextpay  payment plugin.
 *
 * @author Jeremy Magne
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

if (!class_exists('ShopFunctions')) {
    require VMPATH_ADMIN.DS.'helpers'.DS.'shopfunctions.php';
}
if (!class_exists('nextpayHelpernextpay')) {
    require VMPATH_ROOT.DS.'plugins'.DS.'vmpayment'.DS.'nextpay'.DS.'nextpay'.DS.'helpers'.DS.'nextpay.php';
}

JFormHelper::loadFieldClass('list');
jimport('joomla.form.formfield');

class JFormFieldnextpayCreditcards extends JFormFieldList
{
    protected $type = 'nextpaycreditcards';

    protected function getOptions()
    {
        $creditcards = nextpayHelpernextpay::getnextpayCreditCards();

        $prefix = 'VMPAYMENT_nextpay_CC_';

        foreach ($creditcards as $creditcard) {
            $options[] = JHtml::_('select.option', $creditcard, vmText::_($prefix.strtoupper($creditcard)));
        }

        return $options;
    }
}
