<?php

namespace Codeages\Biz\Framework\Order\Service\Impl;

use Codeages\Biz\Framework\Order\Service\OrderService;
use Codeages\Biz\Framework\Util\ArrayToolkit;
use Codeages\Biz\Framework\Service\BaseService;
use Codeages\Biz\Framework\Service\Exception\AccessDeniedException;
use Codeages\Biz\Framework\Service\Exception\InvalidArgumentException;
use Codeages\Biz\Framework\Service\Exception\NotFoundException;
use Codeages\Biz\Framework\Service\Exception\ServiceException;
use Codeages\Biz\Framework\Targetlog\Service\TargetlogService;

class OrderServiceImpl extends BaseService implements OrderService
{
    public function createOrder($fields, $orderItems)
    {
        $this->validateLogin();
        $orderItems = $this->validateFields($fields, $orderItems);
        $fields = ArrayToolkit::parts($fields, array(
            'title',
            'callback',
            'source',
            'user_id',
            'created_reason',
            'seller_id',
            'price_type',
            'deducts'
        ));

        try {
            $this->beginTransaction();
            $order = $this->saveOrder($fields, $orderItems);
            $order = $this->createOrderDeducts($order, $fields);
            $order = $this->createOrderItems($order, $orderItems);
            $this->commit();
        } catch (AccessDeniedException $e) {
            $this->rollback();
            throw $e;
        } catch (InvalidArgumentException $e) {
            $this->rollback();
            throw $e;
        } catch (NotFoundException $e) {
            $this->rollback();
            throw $e;
        } catch (\Exception $e) {
            $this->rollback();
            throw new ServiceException($e);
        }

        $this->dispatch('order.created', $order);
        $this->createOrderLog($order);
        return $order;
    }

    protected function saveOrder($order, $items)
    {
        $user = $this->biz['user'];
        $order['sn'] = $this->generateSn();
        $order['price_amount'] = $this->countOrderPriceAmount($items);
        $order['pay_amount'] = $this->countOrderPayAmount($order['price_amount'], $items);
        $order['created_user_id'] = $user['id'];
        $order = $this->getOrderDao()->create($order);
        return $order;
    }

    protected function createOrderDeducts($order, $fields)
    {
        if(!empty($fields['deducts'])) {
            $orderInfo = ArrayToolkit::parts($order, array(
                'user_id',
                'seller_id',
            ));
            $orderInfo['order_id'] = $order['id'];
            $order['deducts'] = $this->createDeducts($orderInfo, $fields['deducts']);
        }
        return $order;
    }

    protected function countOrderPriceAmount($items)
    {
        $priceAmount = 0;
        foreach ($items as $item) {
            $priceAmount = $priceAmount + $item['price_amount'];
        }
        return $priceAmount;
    }

    protected function countOrderPayAmount($payAmount, $items)
    {
        foreach ($items as $item) {
            if (empty($item['deducts'])) {
                continue;
            }

            foreach ($item['deducts'] as $deduct) {
                $payAmount = $payAmount - $deduct['deduct_amount'];
            }
        }

        if ($payAmount<0) {
            $payAmount = 0;
        }

        return $payAmount;
    }

    protected function generateSn()
    {
        return date('YmdHis', time()).mt_rand(10000, 99999);
    }

    protected function createOrderItems($order, $items)
    {
        $savedItems = array();
        foreach ($items as $item) {
            $deducts = array();
            if (!empty($item['deducts'])) {
                $deducts = $item['deducts'];
                unset($item['deducts']);
            }
            $item['order_id'] = $order['id'];
            $item['seller_id'] = $order['seller_id'];
            $item['user_id'] = $order['user_id'];
            $item['sn'] = $this->generateSn();
            $item['pay_amount'] = $this->countOrderItemPayAmount($item, $deducts);
            $item = $this->getOrderItemDao()->create($item);
            $item['deducts'] = $this->createDeducts($item, $deducts);
            $savedItems[] = $item;
        }

        $order['items'] = $savedItems;
        return $order;
    }

    protected function countOrderItemPayAmount($item, $deducts)
    {
        $priceAmount = $item['price_amount'];

        foreach ($deducts as $deduct) {
            $priceAmount = $priceAmount - $deduct['deduct_amount'];
        }

        return $priceAmount;
    }

    protected function createDeducts($item, $deducts)
    {
        $savedDeducts = array();
        foreach ($deducts as $deduct) {
            $deduct['item_id'] = $item['id'];
            $deduct['order_id'] = $item['order_id'];
            $deduct['seller_id'] = $item['seller_id'];
            $deduct['user_id'] = $item['user_id'];
            $savedDeducts[] = $this->getOrderItemDeductDao()->create($deduct);
        }
        return $savedDeducts;
    }

    protected function validateFields($order, $orderItems)
    {
        if (!ArrayToolkit::requireds($order, array('user_id'))) {
            throw new InvalidArgumentException('user_id is required in order.');
        }

        foreach ($orderItems as $item) {
            if (!ArrayToolkit::requireds($item, array(
                'title',
                'price_amount',
                'target_id',
                'target_type'))) {
                throw new InvalidArgumentException('args is invalid.');
            }
        }

        return $orderItems;
    }

    public function setOrderPaid($data)
    {
        $data = ArrayToolkit::parts($data, array(
            'order_sn',
            'trade_sn',
            'pay_time'
        ));

        try {
            $this->beginTransaction();
            $order = $this->payOrder($data);
            $this->payOrderItems($order);
            $this->commit();
            $this->createOrderLog($order);
        } catch (AccessDeniedException $e) {
            $this->rollback();
            throw $e;
        } catch (InvalidArgumentException $e) {
            $this->rollback();
            throw $e;
        } catch (NotFoundException $e) {
            $this->rollback();
            throw $e;
        } catch (\Exception $e) {
            $this->rollback();
            throw new ServiceException($e);
        }
        $this->dispatch('order.paid', $order);
    }

    protected function payOrder($data)
    {
        $order = $this->getOrderBySn($data['order_sn'], true);
        $data = ArrayToolkit::parts($data, array(
            'trade_sn',
            'pay_time'
        ));
        $data['status'] = 'paid';
        return $this->getOrderDao()->update($order['id'], $data);
    }

    protected function payOrderItems($order)
    {
        $items = $this->getOrderItemDao()->findByOrderId($order['id']);
        $fields = ArrayToolkit::parts($order, array('status'));
        $fields['pay_time'] = $order['pay_time'];
        foreach ($items as $item) {
            $this->getOrderItemDao()->update($item['id'], $fields);
        }
    }

    public function findOrderItemsByOrderId($orderId)
    {
        return $this->getOrderItemDao()->findByOrderId($orderId);
    }

    public function findOrderItemDeductsByItemId($itemId)
    {
        return $this->getOrderItemDeductDao()->findByItemId($itemId);
    }

    public function closeOrder($id)
    {
        try {
            $this->beginTransaction();
            $order = $this->getOrderDao()->get($id, array('lock' => true));
            if ('created' != $order['status']) {
                throw $this->createAccessDeniedException('status is not created.');
            }

            $closeTime = time();
            $order = $this->getOrderDao()->update($id, array(
                'status' => 'close',
                'close_time' => $closeTime
            ));

            $items = $this->findOrderItemsByOrderId($id);
            foreach ($items as $item) {
                $this->getOrderItemDao()->update($item['id'], array(
                    'status' => 'close',
                    'close_time' => $closeTime
                ));
            }
            $this->commit();
        } catch (AccessDeniedException $e) {
            $this->rollback();
            throw $e;
        } catch (InvalidArgumentException $e) {
            $this->rollback();
            throw $e;
        } catch (NotFoundException $e) {
            $this->rollback();
            throw $e;
        } catch (\Exception $e) {
            $this->rollback();
            throw new ServiceException($e->getMessage());
        }
        $this->createOrderLog($order);
        $this->dispatch('order.closed', $order);

        return $order;
    }

    public function closeOrders()
    {
        $orders = $this->getOrderDao()->search(array(
            'created_time_LT' => time()-2*60*60
        ), array('id'=>'DESC'), 0, 1000);

        foreach ($orders as $order) {
            $this->closeOrder($order['id']);
        }
    }

    public function finishOrder($id)
    {
        try {
            $this->beginTransaction();
            $order = $this->getOrderDao()->get($id, array('lock'=>true));
            if ('signed' != $order['status']) {
                throw $this->createAccessDeniedException('status is not paid.');
            }

            $finishTime = time();
            $order = $this->getOrderDao()->update($id, array(
                'status' => 'finish',
                'finish_time' => $finishTime
            ));

            $items = $this->findOrderItemsByOrderId($id);
            foreach ($items as $item) {
                $this->getOrderItemDao()->update($item['id'], array(
                    'status' => 'finish',
                    'finish_time' => $finishTime
                ));
            }
            $this->commit();
        } catch (AccessDeniedException $e) {
            $this->rollback();
            throw $e;
        } catch (InvalidArgumentException $e) {
            $this->rollback();
            throw $e;
        } catch (NotFoundException $e) {
            $this->rollback();
            throw $e;
        } catch (\Exception $e) {
            $this->rollback();
            throw new ServiceException($e->getMessage());
        }
        $this->createOrderLog($order);
        $this->dispatch('order.finished', $order);
        return $order;
    }

    public function finishOrders()
    {
        $orders = $this->getOrderDao()->search(array(
            'pay_time_LT' => time()-2*60*60,
            'status' => 'signed'
        ), array('id'=>'DESC'), 0, 1000);

        foreach ($orders as $order) {
            $this->finishOrder($order['id']);
        }
    }

    public function setOrderShipping($id, $data)
    {
        $order = $this->getOrderDao()->update($id, array(
            'status' => 'shipping',
        ));
        $this->createOrderLog($order, $data);
        return $order;
    }

    public function setOrderSignedSuccess($id, $data)
    {
        return $this->signOrder($id, 'signed', $data);
    }

    public function setOrderSignedFail($id, $data)
    {
        return $this->signOrder($id, 'signed_fail', $data);
    }

    protected function signOrder($id, $status, $data)
    {
        try {
            $this->beginTransaction();
            $order = $this->getOrderDao()->get($id, array('lock'=>true));
            if ('paid' != $order['status']) {
                throw $this->createAccessDeniedException('status is not paid.');
            }

            $signedTime = time();
            $order = $this->getOrderDao()->update($id, array(
                'status' => $status,
                'signed_time' => $signedTime,
                'signed_data' => $data
            ));

            $items = $this->findOrderItemsByOrderId($id);
            foreach ($items as $item) {
                $this->getOrderItemDao()->update($item['id'], array(
                    'status' => $status,
                    'signed_time' => $signedTime,
                    'signed_data' => $data
                ));
            }
            $this->commit();
        } catch (AccessDeniedException $e) {
            $this->rollback();
            throw $e;
        } catch (InvalidArgumentException $e) {
            $this->rollback();
            throw $e;
        } catch (NotFoundException $e) {
            $this->rollback();
            throw $e;
        } catch (\Exception $e) {
            $this->rollback();
            throw new ServiceException($e->getMessage());
        }

        $this->createOrderLog($order, $data);
        $this->dispatch("order.{$status}", $order);

        return $order;
    }

    public function getOrder($id)
    {
        return $this->getOrderDao()->get($id);
    }

    public function getOrderBySn($sn, $lock = false)
    {
        return $this->getOrderDao()->getBySn($sn, array('lock' => $lock));
    }

    public function searchOrders($conditions, $orderBy, $start, $limit)
    {
        return $this->getOrderDao()->search($conditions, $orderBy, $start, $limit);
    }

    public function countOrders($conditions)
    {
        return $this->getOrderDao()->count($conditions);
    }

    public function searchOrderItems($conditions, $orderBy, $start, $limit)
    {
        return $this->getOrderItemDao()->search($conditions, $orderBy, $start, $limit);
    }

    public function countOrderItems($conditions)
    {
        return $this->getOrderItemDao()->count($conditions);
    }

    public function findOrdersByIds(array $ids)
    {
        return $this->getOrderDao()->findByIds($ids);
    }

    public function getOrderRefund($id) {
        return $this->getOrderRefundDao()->get($id);
    }

    protected function validateLogin()
    {
        if (empty($this->biz['user']['id'])) {
            throw new AccessDeniedException('user is not login.');
        }
    }

    protected function createOrderLog($order, $dealData = array())
    {
        $orderLog = array(
            'status' => $order['status'],
            'order_id' => $order['id'],
            'user_id' => $this->biz['user']['id'],
            'deal_data' => $dealData
        );
        return $this->getOrderLogDao()->create($orderLog);
    }

    protected function getOrderDao()
    {
        return $this->biz->dao('Order:OrderDao');
    }

    protected function getOrderRefundDao()
    {
        return $this->biz->dao('Order:OrderRefundDao');
    }

    protected function getOrderItemDao()
    {
        return $this->biz->dao('Order:OrderItemDao');
    }

    protected function getOrderLogDao()
    {
        return $this->biz->dao('Order:OrderLogDao');
    }

    protected function getOrderItemDeductDao()
    {
        return $this->biz->dao('Order:OrderItemDeductDao');
    }

    protected function getOrderItemRefundDao()
    {
        return $this->biz->dao('Order:OrderItemRefundDao');
    }
}