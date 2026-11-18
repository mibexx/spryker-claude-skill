<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\OrderPaymentCheck\Communication\Plugin\Oms\Condition;

use Orm\Zed\Sales\Persistence\SpySalesOrderItem;
use Spryker\Zed\Kernel\Communication\AbstractPlugin;
use Spryker\Zed\Oms\Dependency\Plugin\Condition\ConditionInterface;

/**
 * Condition used by process ExampleOrder01, registered as "ExampleOrder/HasPayment".
 * Evaluated per order item by `oms:check-condition`; returns true -> transition fires.
 * Example rule: a payment record exists for the order. The plugin only delegates:
 * Plugin -> Facade -> Repository (no Propel query in the Communication layer).
 *
 * @method \Pyz\Zed\OrderPaymentCheck\Business\OrderPaymentCheckFacadeInterface getFacade()
 */
class HasPaymentConditionPlugin extends AbstractPlugin implements ConditionInterface
{
    /**
     * {@inheritDoc}
     *
     * @api
     *
     * @param \Orm\Zed\Sales\Persistence\SpySalesOrderItem $orderItem
     *
     * @return bool
     */
    public function check(SpySalesOrderItem $orderItem): bool
    {
        return $this->getFacade()->hasOrderPayment((int)$orderItem->getFkSalesOrder());
    }
}
