<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\OrderPaymentCheck\Persistence;

use Orm\Zed\Payment\Persistence\SpySalesPaymentQuery;
use Spryker\Zed\Kernel\Persistence\AbstractPersistenceFactory;

/**
 * @method \Pyz\Zed\OrderPaymentCheck\Persistence\OrderPaymentCheckRepositoryInterface getRepository()
 */
class OrderPaymentCheckPersistenceFactory extends AbstractPersistenceFactory
{
    public function getSalesPaymentPropelQuery(): SpySalesPaymentQuery
    {
        return SpySalesPaymentQuery::create();
    }
}
