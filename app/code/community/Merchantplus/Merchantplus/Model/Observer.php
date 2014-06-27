<?php
/**
 * NOTICE OF LICENSE
 *
 * @category	Merchantplus
 * @package		Merchantplus_Merchantplus
 * @author		Merchantplus.
 * @license		http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class Merchantplus_Merchantplus_Model_Observer {
	public function disableMethod(Varien_Event_Observer $observer){
		$moduleName="Merchantplus_Merchantplus";
		if('merchantplus'==$observer->getMethodInstance()->getCode()){
			if(!Mage::getStoreConfigFlag('advanced/modules_disable_output/'.$moduleName)) {
				//nothing here, as module is ENABLE
			} else {
				$observer->getResult()->isAvailable=false;
			}
			
		}
	}
}
?>