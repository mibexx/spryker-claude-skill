<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadge\Communication\Controller;

use Generated\Shared\Transfer\ProductBadgeCollectionTransfer;
use Generated\Shared\Transfer\ProductBadgeCriteriaTransfer;
use Spryker\Zed\Kernel\Communication\Controller\AbstractGatewayController;

/**
 * @method \Pyz\Zed\ProductBadge\Business\ProductBadgeFacadeInterface getFacade()
 * @method \Pyz\Zed\ProductBadge\Persistence\ProductBadgeRepositoryInterface getRepository()
 */
class GatewayController extends AbstractGatewayController
{
    public function getProductBadgeCollectionAction(ProductBadgeCriteriaTransfer $productBadgeCriteriaTransfer): ProductBadgeCollectionTransfer
    {
        return $this->getFacade()->getProductBadgeCollection($productBadgeCriteriaTransfer);
    }
}
