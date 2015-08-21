<?php

require_once(Mage::getBaseDir("lib") . "/BcashApi/autoloader.php" );

use Bcash_Pagamento_Helper_RegisterSdk;
use Bcash\Service\Cancellation;
use Bcash\Exception\ValidationException;
use Bcash\Exception\ConnectionException;

/**
 * 
 *
 */
class Bcash_Pagamento_Model_Order extends Mage_Core_Model_Abstract
{

	private $email;
	private $token;
	private $obj;
	private $sandbox;

	public function __construct()
	{
		$this->obj = Mage::getSingleton('Bcash_Pagamento_Model_PaymentMethod');
		$this->email = $this->obj->getConfigData('email');
		$this->token = $this->obj->getConfigData('token');
		$this->sandbox = $this->obj->getConfigData('sandbox');
	}

	public function cancellation($transactionId)
	{

		$cancellation = new Cancellation($this->email, $this->token);
		$cancellation->enableSandBox($this->sandbox);

		try {
		    $transactionId = 186148; // Id da transacao bcash a ser cancelada
		    $response = $cancellation->execute($transactionId);
		    echo "<pre>";
		    var_dump($response);
		    echo "</pre>";

		} catch (ValidationException $e) {
			Mage::getSingleton('adminhtml/session')->addError('Error:' . $e->getMessage());
		    Mage::log($e->getErrors());	

		} catch (ConnectionException $e) {
			Mage::getSingleton('adminhtml/session')->addError('Error:' . $e->getMessage() . ' (Confirme se o serviço de cancelamento está habilitado para sua conta Bcash)');
		    Mage::log($e->getErrors());			    
		}
	}
}