<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\OrderPaymentCheck\Business;

use Spryker\Zed\Kernel\Business\AbstractFacade;

/**
 * @method \Pyz\Zed\OrderPaymentCheck\Business\OrderPaymentCheckBusinessFactory getFactory()
 * @method \Pyz\Zed\OrderPaymentCheck\Persistence\OrderPaymentCheckRepositoryInterface getRepository()
 */
class OrderPaymentCheckFacade extends AbstractFacade implements OrderPaymentCheckFacadeInterface
{
    /**
     * {@inheritDoc}
     *
     * @api
     */
    public function hasOrderPayment(int $idSalesOrder): bool
    {
        return $this->getRepository()->hasSalesPaymentByIdSalesOrder($idSalesOrder);
    }
}
