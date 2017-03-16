<?php

namespace Scanpay\PaymentModule\Model;

use \Magento\Framework\Exception\LocalizedException;
use \Magento\Sales\Model\Order\Payment\Transaction;

class OrderUpdater
{
    const ORDER_DATA_SHOPID = 'scanpay_shopid';
    const ORDER_DATA_REV = 'scanpay_rev';
    const ORDER_DATA_NACTS = 'scanpay_nacts';

    private $logger;
    private $order;
    private $orderSender;
    private $trnBuilder;

    public function __construct(
        \Psr\Log\LoggerInterface $logger,
        \Magento\Sales\Model\Order $order,
        \Magento\Sales\Model\OrderNotifier $orderNotifier,
        \Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface $trnBuilder
    ) {
        $this->logger = $logger;
        $this->order = $order;
        $this->orderNotifier = $orderNotifier;
        $this->trnBuilder = $trnBuilder;
    }

    public function dataIsValid($data)
    {
        return isset($data['id']) && is_int($data['id']) &&
            isset($data['totals']) && is_array($data['totals']) &&
            isset($data['totals']['authorized']) &&
            isset($data['rev']) && is_int($data['rev']);
    }

    public function notifyCustomer($order)
    {
        if (!$order->getEmailSent()) {
            try {
                $this->orderNotifier->notify($order);
            } catch (LocalizedException $e) {
                $this->logger->error('Unable to send order confirmation email for order' .
                    $order->getIncrementId() . ', Exception message: ' . $e->getMessage());
            }
        }

    }

    public function update($shopId, $data)
    {
        /* Ignore errornous transactions */
        if (isset($data['error'])) {
            $this->logger->error('Received error entry in orderupater: ' . $data['error']);
            return true;
        }

        if (!$this->dataIsValid($data)) {
            $this->logger->error('Received invalid order data from Scanpay');
            return false;
        }

        $trnId = $data['id'];
        /* Ignore transactions without order ids */
        if (!isset($data['orderid']) || $data['orderid'] === "") {
            $this->logger->info('Received transaction #' . $trnId . ' without orderid');
            return true;
        }

        $order = $this->order->load($data['orderid']);
        /* If order is not in system, ignore it */
        if (!$order->getId()) {
            $this->logger->info('Order #' . $data['orderid'] . ' not in system');
            return true;
        }

        $newRev = $data['rev'];
        $orderShopId = (int)$order->getData(self::ORDER_DATA_SHOPID);
        $oldRev = (int)$order->getData(self::ORDER_DATA_REV);

        if ($shopId !== $orderShopId) {
            $this->logger->info('Order #' . $data['orderid'] . ' shopid (' .
                $orderShopId . ') does not match current shopid (' .
                $shopId . ')');
            return true;
        }

        if ($newRev <= $oldRev) {
            return true;
        }

        $payment = $order->getPayment();
        $auth = $data['totals']['authorized'];
		
        /* Check if the transaciton is already registered */
        if ($payment->getTransactionId() === null) {
            $payment->setParentTransactionId(null);
            $payment->setLastTransId($trnId);
            $payment->setTransactionId($trnId);
            $payment->setAmountAuthorized(explode(' ', $auth)[0]);
            $payment->save(); /* Save here to avoid exceptions from multiple auth trns per order */

            $transaction = $payment->addTransaction(Transaction::TYPE_AUTH, null, true);
			$transaction->setOrderId($order->getId());
            $transaction->save();
            $payment->addTransactionCommentsToOrder($transaction, __('The authorized amount is %1.', $auth));
        }

        if ($order->getState() === \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT) {
            $state = \Magento\Sales\Model\Order::STATE_PROCESSING;
            $order->setState($state);
            $order->setStatus($order->getConfig()->getStateDefaultStatus($state));
        }


        if (isset($data['acts']) && is_array($data['acts'])) {
            $nacts = (int)$order->getData(self::ORDER_DATA_NACTS);
            for ($i = $nacts; $i < count($data['acts']); $i++) {
                $act = $data['acts'][$i];
                switch ($act['act']) {
                case 'capture':
                    $transaction = $payment->addTransaction(Transaction::TYPE_CAPTURE, null, true);
					$transaction->setOrderId($order->getId());
                    $transaction->save();
                    if (isset($act['total']) && is_string($act['total'])) {
                        $payment->addTransactionCommentsToOrder($transaction, __('The captured amount is %1.', $act['total']));
                    }
                    break;

                case 'refund':
                    $transaction = $payment->addTransaction(Transaction::TYPE_REFUND, null, true);
					$transaction->setOrderId($order->getId());
                    $transaction->save();
                    if (isset($act['total']) && is_string($act['total'])) {
                        $payment->addTransactionCommentsToOrder($transaction, __('The refunded amount is %1.', $act['total']));
                    }
                    break;
                }
            }

            $order->setData(self::ORDER_DATA_NACTS, count($data['acts']));

            if (isset($data['totals']['captured'])) {
                $payment->setAmountPaid(explode(' ', $data['totals']['captured'])[0]);
            }

            if (isset($data['totals']['refunded'])) {
                $payment->setAmountRefunded(explode(' ', $data['totals']['refunded'])[0]);
            }
        }

        $order->setData(self::ORDER_DATA_REV, $data['rev']);
        $payment->save();
        $order->save();
        /* Send email AFTER payment has been set */
        $this->notifyCustomer($order);
        return true;
    }

    public function updateAll($shopId, $changes)
    {
        foreach ($changes as $trn) {
            if (!$this->update($shopId, $trn)) {
                return false;
            }
        }
        return true;
    }
}
