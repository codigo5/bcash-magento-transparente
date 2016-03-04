<?php

require_once(Mage::getBaseDir("lib") . "/Bcash/AutoLoader.php");
Bcash\AutoLoader::register();

use Bcash\Service\Notification;
use Bcash\Domain\NotificationContent;
use Bcash\Domain\NotificationStatusEnum;
use Bcash\Exception\ValidationException;
use Bcash\Exception\ConnectionException;
use Bcash\Test\NotificationSimulator;
use Bcash\Config\Config;

/**
 * Controller Bcash_Pagamento_NotificationController
 */
class Bcash_Pagamento_NotificationController extends Mage_Core_Controller_Front_Action
{

    private $email;
    private $token;
    private $obj;
    private $sandbox;

    protected function _construct()
    {
        // access log (debug)
        Mage::helper("bcash")->saveLog('Step 1 - Notification visitor: ' . Mage::helper('core/http')->getRemoteAddr());

        $this->email = Mage::getStoreConfig('payment/bcash/email');
        $this->token = Mage::getStoreConfig('payment/bcash/token');
        $this->sandbox = Mage::getStoreConfig('payment/bcash/sandbox');
    }

    /**
     * Index
     */
    public function indexAction()
    {
        if ($this->sandbox) {
            // Get request
            $transactionId = Mage::app()->getRequest()->getParam('transactionId');
            $orderId = trim(stripslashes(Mage::app()->getRequest()->getParam('orderId')));
            $statusId = (int)Mage::app()->getRequest()->getParam('statusId');

            // Notification Simulator
            $urlSubmit = Mage::getUrl('bcash/notification/index', array('_secure' => true));
            echo "<h1>Bcash Notification Simulator</h1>
              <form method='GET' action='" . $urlSubmit . "'>
                <label>Nro. Pedido:</label>
                <input type='text' name='orderId' placeholder='Nro. Pedido' value='" . $orderId . "'/>
                <label>Nro. Transação:</label>
                <input type='text' name='transactionId' placeholder='Nro. Transação' value='" . $transactionId . "'/>
                <label>Status:</label>
                <select name='statusId'>
                    <option value='1' " . ($statusId == 1 ? "selected" : "") . ">Em andamento</option>
                    <option value='3' " . ($statusId == 3 ? "selected" : "") . ">Aprovada</option>
                    <option value='4' " . ($statusId == 4 ? "selected" : "") . ">Concluída</option>
                    <option value='5' " . ($statusId == 5 ? "selected" : "") . ">Em disputa</option>
                    <option value='6' " . ($statusId == 6 ? "selected" : "") . ">Devolvida</option>
                    <option value='7' " . ($statusId == 7 ? "selected" : "") . ">Cancelada</option>
                    <option value='8' " . ($statusId == 8 ? "selected" : "") . ">Chargeback</option>
                </select>
                <input type='submit' value='Enviar'/>
             </form>";

            if (!empty($transactionId) && !empty($statusId) && !empty($orderId)) {
                $urlSimulator = Mage::getUrl('bcash/notification/request', array('_secure' => true));
                $returnSimulator = $this->notificationSimulator($urlSimulator, $transactionId, $orderId, $statusId);
                echo "<h2>Retorno:</h2> <div style='clear:both;'></div><pre style='background-color: #EAEAEA; padding:20px;'>";
                var_dump($returnSimulator);
                echo "</pre>";
            }
            echo "<div style='clear:both;'><div style='float: right;margin-top: 20px; font-size: 12px;'>hostSandBox: " . Config::hostSandBox . "</div>";
        } else {
            echo "Habilite o Sandbox para simular notificações pelo Bcash.";
        }
    }

    /**
     * Recebimento de notificações pela API
     */
    public function requestAction()
    {
        // GET THIS URL -> Mage::getUrl('pagamento/notification/request')

        // POST request
        $transactionId = Mage::app()->getRequest()->getParam('transacao_id');
        $orderId = trim(stripslashes(Mage::app()->getRequest()->getParam('pedido')));
        $statusId = (int)Mage::app()->getRequest()->getParam('status_id');
        $status = Mage::app()->getRequest()->getParam('status');

        // Instância de classe de notificação
        $notificationContent = new NotificationContent($transactionId, $orderId, $statusId);
        $notification = new Notification($this->email, $this->token, $notificationContent);
        $notification->enableSandBox($this->sandbox);

        try {
            // Request log
            Mage::helper("bcash")->saveLog('Step 2 - Informacoes da requisicao recebida: {transacao_id: ' . $transactionId . ', pedido: ' . $orderId . ', status_id: ' . $statusId . ', status: ' . $status . '}');

            $order = Mage::getModel('sales/order')->loadByIncrementId($orderId);
            if ($order->getData() != null) {
                // Checa requisição válida através de valor total do pedido (valor dos produtos + frete + acréscimo - desconto)
                $transactionValue = $order->getBaseGrandTotal();
                $validNotification = $notification->verify($transactionValue);

                if ($validNotification == true) {
                    // Checa se o código da transação está relacionado com o pedido
                    $transactionId = "111";
                    if ($this->isValidTransactionForOrder($order, $transactionId)) {
                        Mage::helper("bcash")->saveLog("Step 3 - Notificacao pode ser processada no pedido.");

                        // Registro de notificação recebida
                        $order->addStatusHistoryComment('Notificação da transação de pagamento recebida. Status: ' . $status);
                        $order->save();

                        // Processamento da notificação no pedido
                        $this->processNotification($transactionId, $orderId, $statusId);
                    } else {
                        Mage::helper("bcash")->saveLog("Step 3 - A transacao recebida nao esta relacionada com o pedido e nao sera processada.");
                    }
                } else {
                    Mage::helper("bcash")->saveLog("Step 3 - Notificacao invalida recebida.");
                }
            } else {
                Mage::helper("bcash")->saveLog("Step 3 - Atencao!!! Pedido " . $orderId . " nao encontrado. ");
            }
        } catch (ValidationException $e) {
            Mage::helper("bcash")->saveLog("Validation error - NotificationController->requestAction: " . $e->getMessage(), $e->getErrors());

        } catch (ConnectionException $e) {
            Mage::helper("bcash")->saveLog("Connection error - NotificationController->requestAction: " . $e->getMessage(), $e->getErrors());
        }
    }

    /**
     * Atualização de status do pedido conforme retorno da notificação
     *
     * @param $transactionId
     * @param $orderId
     * @param $statusId
     * @throws Exception
     */
    private function processNotification($transactionId, $orderId, $statusId)
    {
        // Carrega pedido a partir de código incremental
        $order = Mage::getModel('sales/order')->loadByIncrementId($orderId);
        $orderId = $order->getId();
        $quoteId = $order->getQuoteId();

        switch ($statusId) {
            case NotificationStatusEnum::APPROVED:
                if ($order->getState() == Mage_Sales_Model_Order::STATE_PENDING_PAYMENT) {
                    $BaseGrandtotal = $order->getBaseGrandTotal();
                    $payment = $order->getPayment();
                    $payment->setTransactionId($transactionId)
                        ->setCurrencyCode($order->getBaseCurrencyCode())
                        ->setPreparedMessage("Pagamento aprovado.")
                        ->setIsTransactionClosed(1)
                        ->registerCaptureNotification($BaseGrandtotal);
                    $order->save();
                }
                // Atualiza status na transação
                $quote = Mage::getModel('sales/quote')->loadByIdWithoutStore($quoteId);
                $quote->setStatusBcash($statusId)
                    ->setDescriptionStatusBcash("Aprovada");
                $quote->save();
                break;
            case NotificationStatusEnum::COMPLETED:
                // Atualiza status na transação
                $quote = Mage::getModel('sales/quote')->loadByIdWithoutStore($quoteId);
                $quote->setStatusBcash($statusId)
                    ->setDescriptionStatusBcash("Concluída");
                $quote->save();
                break;
            case NotificationStatusEnum::IN_PROGRESS:
                if ($order->getState() != Mage_Sales_Model_Order::STATE_PENDING_PAYMENT) {
                    $payment = $order->getPayment();
                    $payment->setTransactionId($transactionId);
                    $payment->setIsTransactionClosed(0);
                    $payment->setTransactionAdditionalInfo(Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS, array('Status' => 'Em andamento'));
                    $order->setState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT, true)->save();
                }
                // Atualiza status na transação
                $quote = Mage::getModel('sales/quote')->loadByIdWithoutStore($quoteId);
                $quote->setStatusBcash($statusId)
                    ->setDescriptionStatusBcash("Em andamento");
                $quote->save();
                break;
            case NotificationStatusEnum::CANCELLED:
                $order->registerCancellation('Pagamento cancelado.', TRUE)->save();
                $order->setState(Mage_Sales_Model_Order::STATE_CANCELED, true);
                $order->save();

                // Atualiza status na transação
                $quote = Mage::getModel('sales/quote')->loadByIdWithoutStore($quoteId);
                $quote->setStatusBcash($statusId)
                    ->setDescriptionStatusBcash("Cancelada");
                $quote->save();
                break;
            case NotificationStatusEnum::IN_DISPUTE:
                $order->addStatusHistoryComment('A transação ' . $transactionId . ' está com status EM DISPUTA. Entre em contato com o Bcash.');
                if ($order->canHold()) {
                    $order->hold();
                } else {
                    $order->setState(Mage_Sales_Model_Order::STATE_HOLDED, true);
                }
                $order->save();

                // Atualiza status na transação
                $quote = Mage::getModel('sales/quote')->loadByIdWithoutStore($quoteId);
                $quote->setStatusBcash($statusId)
                    ->setDescriptionStatusBcash("Em disputa");
                $quote->save();
                break;
            case NotificationStatusEnum::CHARGEBACK:
                $order->addStatusHistoryComment('A transação ' . $transactionId . ' está com status CHARGEBACK EM ANÁLISE. Entre em contato com o Bcash.');
                if ($order->canHold()) {
                    $order->hold();
                } else {
                    $order->setState(Mage_Sales_Model_Order::STATE_HOLDED, true);
                }
                $order->save();

                // Atualiza status na transação
                $quote = Mage::getModel('sales/quote')->loadByIdWithoutStore($quoteId);
                $quote->setStatusBcash($statusId)
                    ->setDescriptionStatusBcash("Chargeback");
                $quote->save();
                break;
            case NotificationStatusEnum::REFUNDED:
                $order->setState(Mage_Sales_Model_Order::STATE_CANCELED, true)->save();

                // Atualiza status na transação
                $quote = Mage::getModel('sales/quote')->loadByIdWithoutStore($quoteId);
                $quote->setStatusBcash($statusId)
                    ->setDescriptionStatusBcash("Devolvida");
                $quote->save();
                break;
            default:
                $order->addStatusHistoryComment('Notificação da transação ' . $transactionId . ' sem ação identificada para o status ' . $statusId);
                $order->save();
                break;
        }

        // Sinc datas
        Mage::helper('bcash')->updateOrderSyncBcashDataWithQuote($orderId, $quoteId);
    }

    /**
     * Verifica se a transação está relacionada com o pedido
     *
     * @param $order
     * @param $transactionId
     * @return bool
     */
    private function isValidTransactionForOrder($order, $transactionId)
    {
        try {
            $orderTransactionId = $order->getTransactionIdBcash();
            if ($orderTransactionId == $transactionId) {
                return true;
            }
            // Comparação de string segura para binário
            if (strcmp($orderTransactionId, $transactionId) == 0) {
                return true;
            }
        } catch (Exception $e) {
            Mage::helper("bcash")->saveLog("Erro inesperado: " . $e->getMessage());
        }

        return false;
    }

    /**
     * Simulação de notificação enviada pelo Bcash na URL de retorno
     *
     * @param $notificationUrl
     * @param $transactionId
     * @param $orderId
     * @param $statusId
     */
    private function notificationSimulator($notificationUrl, $transactionId, $orderId, $statusId)
    {
        try {
            $result = NotificationSimulator::test($notificationUrl, $transactionId, $orderId, $statusId);
            return $result;
        } catch (ConnectionException $e) {
            return $e->getMessage();
        }
    }
}