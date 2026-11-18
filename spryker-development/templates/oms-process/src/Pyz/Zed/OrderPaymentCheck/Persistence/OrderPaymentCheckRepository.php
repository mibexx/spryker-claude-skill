<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\OrderPaymentCheck\Persistence;

use Spryker\Zed\Kernel\Persistence\AbstractRepository;

/**
 * @method \Pyz\Zed\OrderPaymentCheck\Persistence\OrderPaymentCheckPersistenceFactory getFactory()
 */
class OrderPaymentCheckRepository extends AbstractRepository implements OrderPaymentCheckRepositoryInterface
{
    public function hasSalesPaymentByIdSalesOrder(int $idSalesOrder): bool
    {
        return $this->getFactory()
            ->getSalesPaymentPropelQuery()
            ->filterByFkSalesOrder($idSalesOrder)
            ->exists();
    }
}
