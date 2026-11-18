<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\OrderPaymentCheck\Communication\Plugin\Oms\Command;

use Orm\Zed\Sales\Persistence\SpySalesOrder;
use Spryker\Shared\Log\LoggerTrait;
use Spryker\Zed\Kernel\Communication\AbstractPlugin;
use Spryker\Zed\Oms\Business\Util\ReadOnlyArrayObject;
use Spryker\Zed\Oms\Dependency\Plugin\Command\CommandByOrderInterface;

/**
 * Command used by process ExampleOrder01, event "confirm", registered as "ExampleOrder/LogOrderConfirmed".
 * Keep it thin: delegate real business logic to a facade, never touch foreign persistence directly.
 *
 * @method \Pyz\Zed\OrderPaymentCheck\Business\OrderPaymentCheckFacadeInterface getFacade()
 */
class LogOrderConfirmedCommandPlugin extends AbstractPlugin implements CommandByOrderInterface
{
    use LoggerTrait;

    /**
     * {@inheritDoc}
     * - Runs once per order for the grouped order items (same order and state) which triggered the event.
     * - Return value: array (may be empty), can contain data for the process.
     *
     * @api
     *
     * @param array<\Orm\Zed\Sales\Persistence\SpySalesOrderItem> $orderItems
     * @param \Orm\Zed\Sales\Persistence\SpySalesOrder $orderEntity
     * @param \Spryker\Zed\Oms\Business\Util\ReadOnlyArrayObject $data
     *
     * @return array<mixed>
     */
    public function run(array $orderItems, SpySalesOrder $orderEntity, ReadOnlyArrayObject $data): array
    {
        $this->getLogger()->info(sprintf(
            'ExampleOrder01: order "%s" confirmed, %d item(s).',
            $orderEntity->getOrderReference(),
            count($orderItems),
        ), $data->getArrayCopy());

        return [];
    }
}
