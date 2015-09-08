<?php

/**
 * Classe para reescrita de funcionalidades no Block de exibição de pedido
 */
class Bcash_Pagamento_Block_Adminhtml_Sales_Order_View extends Mage_Adminhtml_Block_Sales_Order_View
{

    public function  __construct()
    {
        parent::__construct();

        $order = $this->getOrder();

        // Consulta transaçao Bcash
        $quoteId = $order->getQuoteId();
        $quote = Mage::getModel('sales/quote')->loadByIdWithoutStore($quoteId);
        $transactionIdBcash = $quote->getTransactionIdBcash();
        $transactionInfo = Mage::helper('pagamento')->getTransaction($transactionIdBcash);

        // Checa se o status da transaçao Bcash permite cancelamento
        // NotificationStatusEnum -> const IN_PROGRESS = 1;
        // NotificationStatusEnum -> const APPROVED = 3;
        if($transactionInfo->transacao->cod_status == 1 || $transactionInfo->transacao->cod_status == 3) {
            if ($order->canCancel() || $order->canCreditmemo()) {
                $confirmationMessage = Mage::helper('core')->jsQuoteEscape(
                    Mage::helper('sales')->__('Deseja realmente cancelar o pedido ' . $order->getId() . '? \n\nA transação de pagamento também será cancelada.')
                );
                $this->_addButton('button_cancel_with_bcash', array(
                    'label' => 'Cancelar com Bcash',
                    'onclick' => 'deleteConfirm(\'' . $confirmationMessage . '\', \'' . $this->getUrl('pagamento/admin_sales_order/canceltransaction/') . '\')',
                ), 0, 100, 'header', 'header');
            }
        }
    }
}