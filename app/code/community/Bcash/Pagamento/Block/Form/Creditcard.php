<?php

require_once(Mage::getBaseDir("lib") . "/Bcash/AutoLoader.php");
Bcash\AutoLoader::register();

use Bcash\Service\Installments;
use Bcash\Exception\ValidationException;
use Bcash\Exception\ConnectionException;
use Bcash\Domain\PaymentMethodEnum;

/**
 * Class Bcash_Pagamento_Block_Form_Creditcard
 */
class Bcash_Pagamento_Block_Form_Creditcard extends Mage_Payment_Block_Form
{
    /**
     * @var string
     */
    protected $_code = 'bcash_creditcard';
    /**
     * @var
     */
    private $email;
    /**
     * @var
     */
    private $token;
    /**
     * @var
     */
    private $sandbox;
    /**
     * @var
     */
    private $max_installments;

    /**
     * @var
     */
    private $cards;
    /**
    * @var
    */
    private $boleto;
    /**
    * @var
    */
    private $tefs;
    /**
     * @var
     */
    private $desconto_credito_1x;
    /**
     * @var
     */
    private $quote;
    /**
     * @var
     */
    private $cpf;
    /**
     * @var
     */
    private $phone;

    /**
     * Instancia o template referente ao método de pagamento
     */
    public function __construct()
    {
        parent::__construct();
        $this->setTemplate('bcash/pagamento/form/creditcard.phtml');
        $this->email   = Mage::getStoreConfig('payment/bcash/email');
        $this->token   = Mage::getStoreConfig('payment/bcash/token');
        $this->sandbox = Mage::getStoreConfig('payment/bcash/sandbox');
        $this->max_installments = Mage::getStoreConfig('payment/bcash_creditcard/max_installments');
        $this->cpf = Mage::getStoreConfig('payment/bcash/cpf');
        $this->phone = Mage::getStoreConfig('payment/bcash/phone');
        $this->desconto_credito_1x = 0;
        $this->cards  = array(PaymentMethodEnum::VISA, PaymentMethodEnum::MASTERCARD, PaymentMethodEnum::AMERICAN_EXPRESS, PaymentMethodEnum::AURA, PaymentMethodEnum::DINERS, PaymentMethodEnum::HIPERCARD, PaymentMethodEnum::ELO);
        $this->boleto = PaymentMethodEnum::BANK_SLIP;
        $this->tefs   = array(PaymentMethodEnum::BB_ONLINE_TRANSFER, PaymentMethodEnum::BRADESCO_ONLINE_TRANSFER, PaymentMethodEnum::ITAU_ONLINE_TRANSFER, PaymentMethodEnum::BANRISUL_ONLINE_TRANSFER, PaymentMethodEnum::HSBC_ONLINE_TRANSFER);
    }

    public function getCpf()
    {
        return $this->cpf;
    }

    public function getPhone()
    {
        return $this->phone;
    }

    /**
     * Retorna valor de CPF/CNPJ do comprador
     *
     * @return mixed
     */
    public function getCurrentCustomerTaxvat()
    {
        $taxvat = "";
        try {
            if (Mage::getSingleton('customer/session')->isLoggedIn()) {
                $customerData = Mage::getSingleton('customer/session')->getCustomer();
                $taxvat = $customerData->getData('taxvat');
            }
        } catch (Exception $e) {
            Mage::helper("bcash")->saveLog("Form_Bankslip::getCurrentCustomerTaxvat Exception: " . $e->getMessage(), $e->getErrors());
        }

        return preg_replace('/[^0-9]+/', '', $taxvat);
    }

    /**
     * Retorna os meios de pagamento disponíveis no módulo Bcash.
     * @return mixed
     */
    public function getPaymentMethods()
    {
        //Mage::helper("bcash")->saveLog("Bcash_Pagamento_Block_Form_Creditcard called getPaymentMethods OK");
        // Find allowed payment methods
        $listAllowed = $this->getAllowedPaymentMethods();

        return Mage::helper('bcash/paymentMethod')->getPaymentMethods($listAllowed, "creditcard");
    }

    /**
     * Retorna os parcelamentos possíveis via cartão de crédito.
     * @return array
     */
    public function getInstallments()
    {
        $installments = new Installments($this->email, $this->token);
        try {
            $sessionCheckout = Mage::getSingleton('checkout/session');
            $quoteId = $sessionCheckout->getQuoteId();
            $this->quote = Mage::getModel("sales/quote")->load($quoteId);
            $grandTotal = floatval(number_format($this->quote->getData('grand_total'), 2, '.', ''));
            $ignoreScheduledDiscount = false;
            if($this->sandbox){
                $installments->enableSandBox(true);
            }
            $response = $installments->calculate($grandTotal, $this->max_installments, $ignoreScheduledDiscount);
            return array("ok" => true, "installments" => array(0 => $this->prepareInstallmentsCards($response)));
        } catch (ValidationException $e) {
            Mage::helper("bcash")->saveLog("ValidationException - Form_Creditcard::getInstallments:" . $e->getMessage(), $e->getErrors());
            return array("ok" => false, "installments" => array("1" => $grandTotal));
        } catch (ConnectionException $e) {
            Mage::helper("bcash")->saveLog("ConnectionException - Form_Creditcard::getInstallments:" . $e->getMessage(), $e->getErrors());
            return array("ok" => false, "installments" => array("1" => $grandTotal));
        }
    }

    public function prepareInstallmentsCards($installments)
    {
        foreach ($installments->paymentTypes as $obj) {
            if ('card' != $obj->name) {
                unset($obj);
            } else {
                if ($this->desconto_credito_1x) {
                    $subTotal = floatval($this->quote->getSubtotal());
                    $desconto = ($this->desconto_credito_1x / 100) * $subTotal;
                    if ($desconto) {
                        foreach ($obj->paymentMethods as $type) {
                            foreach ($type->installments as &$installment) {
                                if ($installment->number == 1) {
                                    $installment->installmentAmount -= $desconto;
                                    $installment->installmentAmountDesc = " ({$this->desconto_credito_1x} % de desconto)";
                                    break;
                                }
                            }
                        }
                    }
                }
            }
        }
        return $installments;
    }

    private function getAllowedPaymentMethods()
    {
        $methods = array();
        $installments = new Installments($this->email, $this->token);
        try {
            $installments->enableSandBox($this->sandbox);
            // Any param value just for check
            $response = $installments->calculate(100.00, 1, false);
            // list methods
            foreach($response->paymentTypes as $types) {
                if($types->name == 'card') {
                    foreach($types->paymentMethods as $method) {
                        $methods[] = $method->id;
                    }
                }
            }
        } catch (ValidationException $e) {
            Mage::helper("bcash")->saveLog("ValidationException - Form_Creditcard::getAllowedPaymentMethods:" . $e->getMessage(), $e->getErrors());
        } catch (ConnectionException $e) {
            Mage::helper("bcash")->saveLog("ConnectionException - Form_Creditcard::getAllowedPaymentMethods:" . $e->getMessage(), $e->getErrors());
        }

        return $methods;
    }
}


