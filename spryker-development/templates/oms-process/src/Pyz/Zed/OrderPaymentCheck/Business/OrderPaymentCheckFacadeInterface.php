<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\OrderPaymentCheck\Business;

interface OrderPaymentCheckFacadeInterface
{
    /**
     * Specification:
     * - Checks whether at least one payment record (`spy_sales_payment`) exists for the given sales order.
     *
     * @api
     */
    public function hasOrderPayment(int $idSalesOrder): bool;
}
