<?php
/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * which is available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you are unable to obtain it through the world-wide-web,
 * please send an email to magento@raveinfosys.com
 * so we can send you a copy immediately.
 *
 * @category	Montazze
 * @package		Merchantplus_Merchantplus
 * @author		Montazze Studio.
 * @license		http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class Merchantplus_Merchantplus_Model_Merchantplus extends Mage_Payment_Model_Method_Cc {
	
	protected $_code						=	'merchantplus';	//unique internal payment method identifier
	
	protected $_isGateway					=	true;
    protected $_canAuthorize				=	true;
    protected $_canCapture					=	true;
    protected $_canCapturePartial			=	false;
    protected $_canRefund					=	false;
	protected $_canRefundInvoicePartial		=	false;
    protected $_canVoid						=	false;
    protected $_canUseInternal				=	true;
    protected $_canUseCheckout				=	true;
    protected $_canUseForMultishipping		=	false;
    protected $_isInitializeNeeded			=	false;
    protected $_canFetchTransactionInfo		=	false;
    protected $_canReviewPayment			=	false;
    protected $_canCreateBillingAgreement	=	false;
    protected $_canManageRecurringProfiles	=	false;
	protected $_canSaveCc					=	false;
	
	/**
     * Fields that should be replaced in debug with '***'
     *
     * @var array
     */
    protected $_debugReplacePrivateDataKeys = array('x_login', 'x_tran_key', 'x_card_num', 'x_exp_date', 'x_card_code');

	/**
     * Validate payment method information object
     */
	public function validate() {
		$info = $this->getInfoInstance();
		$order_amount=0;
		if ($info instanceof Mage_Sales_Model_Quote_Payment) {
            $order_amount=(double)$info->getQuote()->getBaseGrandTotal();
        } elseif ($info instanceof Mage_Sales_Model_Order_Payment) {
            $order_amount=(double)$info->getOrder()->getQuoteBaseGrandTotal();
        }
		
		$order_min=$this->getConfigData('min_order_total');
		$order_max=$this->getConfigData('max_order_total');
		if(!empty($order_max) && (double)$order_max<$order_amount) {
			Mage::throwException("Order amount greater than permissible Maximum order amount.");
		}
		if(!empty($order_min) && (double)$order_min>$order_amount) {
			Mage::throwException("Order amount less than required Minimum order amount.");
		}
		/*
        * calling parent validate function
        */
        parent::validate();
	}
	
	/**
     * Send capture request to gateway     
     */
	public function capture(Varien_Object $payment, $amount) {
		if ($amount <= 0) {            
			Mage::throwException("Invalid amount for transaction.");			
        }
		
		$shipping=array();
		$billing=array();
		
		$payment->setAmount($amount);		
		$data = $this->_prepareData();		
		$order = $payment->getOrder();			
		
		// AIM Head
		$data['x_version']           = '3.1';
		
		// TRUE Means that the Response is going to be delimited
		$data['x_delim_data']        = 'TRUE';
		$data['x_delim_char']        = '|';
		$data['x_relay_response']    = 'FALSE';
		
		// Transaction Info
		$data['x_method']            = 'CC';
		$data['x_amount']            = $payment->getAmount();
		
		// Test Card
		$month = ($payment->getCcExpMonth()<9?'0'.$payment->getCcExpMonth():$payment->getCcExpMonth());
		$data['x_card_num']          = $payment->getCcNumber();
		$data['x_exp_date']          = $month.substr($payment->getCcExpYear(),-2);
		$data['x_card_code']         = $payment->getCcCid();			
		$data['x_trans_id']          = '';
		
		// Order Info
		$data['x_invoice_num']       = '';
		$data['x_description']       = '';
		
		if (!empty($order)) {
			$BillingAddress				 = $order->getBillingAddress();
			
			// Customer Info
			$data['x_first_name']        = $BillingAddress->getFirstname();
			$data['x_last_name']         = $BillingAddress->getLastname();
			$data['x_company']           = $BillingAddress->getCompany();
			$data['x_address']           = $BillingAddress->getStreet(1);
			$data['x_city']              = $BillingAddress->getCity();
			$data['x_state']             = $BillingAddress->getRegion();
			$data['x_zip']               = $BillingAddress->getPostcode();
			$data['x_country']           = $BillingAddress->getCountry();
			$data['x_phone']             = $BillingAddress->getTelephone();
			$data['x_fax']               = $BillingAddress->getFax();
			$data['x_email']             = $order->getCustomerEmail();
			$data['x_cust_id']           = '';
			$data['x_customer_ip']       = '';
			
			$ShippingAddress			 = $order->getShippingAddress();
			if (empty($shipping)) {				
				$ShippingAddress		 = $BillingAddress;
			}
			
			// shipping info
			$data['x_ship_to_first_name']= $ShippingAddress->getFirstname();
			$data['x_ship_to_last_name'] = $ShippingAddress->getLastname();
			$data['x_ship_to_company']   = $ShippingAddress->getCompany();
			$data['x_ship_to_address']   = $ShippingAddress->getStreet(1);
			$data['x_ship_to_city']      = $ShippingAddress->getCity();
			$data['x_ship_to_state']     = $ShippingAddress->getRegion();
			$data['x_ship_to_zip']       = $ShippingAddress->getPostcode();
			$data['x_ship_to_country']   = $ShippingAddress->getCountry();
		}
		
		$result = $this->_postRequest($data);
		if ($result['http_code']>=200 && $result['http_code']<300){										
			$data = $result['body'];
			$delim = $data{1};
			$data = explode($delim, $data);	
			
			if($data[0] == 1){
				$payment->setStatus(self::STATUS_APPROVED);
				$payment->setLastTransId((string)$data[6]);
				if (!$payment->getParentTransactionId() || (string)$data[6] != $payment->getParentTransactionId()) {
					$payment->setTransactionId((string)$data[6]);
				}
				return $this;		
			}else{
				$error = $this->error_status();
				$payment->setStatus(self::STATUS_ERROR);
				Mage::throwException("Gateway error : {".(string)$error[$data[0]][$data[2]]."}");				
			}
		}else{
			Mage::throwException("No response found");
		}			
	}
		
	/**
     * process using cURL
     */
	protected function _postRequest($data) {
		$debugData = array('request' => $data);	
		
		$url = 'https://gateway.merchantplus.com/cgi-bin/PAWebClient.cgi';
		
		$request = curl_init($url);
		curl_setopt($request, CURLOPT_HEADER, 0);
		curl_setopt($request, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($request, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));
		curl_setopt($request, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($request, CURLOPT_SSL_VERIFYHOST, false);
		$response = curl_exec($request);			
		$info = curl_getinfo($request);
		curl_close($request);		

		$debugData['response'] = $response;
		$this->_debug($debugData);
		
		return array('http_code'=>$info['http_code'], 'body'=>$response);	
	}
	
	protected function _prepareData() {
		$data=array(			
			'x_login'			=>	$this->getConfigData('login_id'),
			'x_tran_key'		=>	$this->getConfigData('trans_key'),
			'x_type'			=>	$this->getConfigData('method'),
			'x_test_request'	=>	($this->getConfigData('test') == 1?'TRUE':'FALSE')
		);			
		if(empty($data['x_login']) || empty($data['x_tran_key'])){
			Mage::throwException("Gateway Parameters Missing. Contact administrator!");
		}
		return $data;
	}
	
	function _error_status() {
		$error[2][2] 	= 'This transaction has been declined.';
		$error[3][6] 	= 'The credit card number is invalid.';
		$error[3][7] 	= 'The credit card expiration date is invalid.';
		$error[3][8] 	= 'The credit card expiration date is invalid.';
		$error[3][13] 	= 'The merchant Login ID or Password or TransactionKey is invalid or the account is inactive.';
		$error[3][15] 	= 'The transaction ID is invalid.';
		$error[3][16] 	= 'The transaction was not found';
		$error[3][17] 	= 'The merchant does not accept this type of credit card.';
		$error[3][19] 	= 'An error occurred during processing. Please try again in 5 minutes.';
		$error[3][33] 	= 'A required field is missing.';
		$error[3][42] 	= 'There is missing or invalid information in a parameter field.';
		$error[3][47] 	= 'The amount requested for settlement may not be greater than the original amount authorized.';
		$error[3][49] 	= 'A transaction amount equal or greater than $100000 will not be accepted.';
		$error[3][50] 	= 'This transaction is awaiting settlement and cannot be refunded.';
		$error[3][51] 	= 'The sum of all credits against this transaction is greater than the original transaction amount.';
		$error[3][57] 	= 'A transaction amount less than $1 will not be accepted.';
		$error[3][64] 	= 'The referenced transaction was not approved.';
		$error[3][69] 	= 'The transaction type is invalid.';
		$error[3][70] 	= 'The transaction method is invalid.';
		$error[3][72] 	= 'The authorization code is invalid.';
		$error[3][73] 	= 'The driver\'s license date of birth is invalid.';
		$error[3][84] 	= 'The referenced transaction was already voided.';
		$error[3][85] 	= 'The referenced transaction has already been settled and cannot be voided.';
		$error[3][86] 	= 'Your settlements will occur in less than 5 minutes. It is too late to void any existing transactions.';
		$error[3][87] 	= 'The transaction submitted for settlement was not originally an AUTH_ONLY.';
		$error[3][88] 	= 'Your account does not have access to perform that action.';
		$error[3][89] 	= 'The referenced transaction was already refunded.';
		$error[3][90] 	= 'Data Base Error.';
		
		return $error;
	}
}
?>