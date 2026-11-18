<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadge\Business;

use Generated\Shared\Transfer\ProductBadgeCollectionTransfer;
use Generated\Shared\Transfer\ProductBadgeCriteriaTransfer;
use Generated\Shared\Transfer\ProductBadgeTransfer;
use Spryker\Zed\Kernel\Business\AbstractFacade;

/**
 * @method \Pyz\Zed\ProductBadge\Business\ProductBadgeBusinessFactory getFactory()
 * @method \Pyz\Zed\ProductBadge\Persistence\ProductBadgeRepositoryInterface getRepository()
 * @method \Pyz\Zed\ProductBadge\Persistence\ProductBadgeEntityManagerInterface getEntityManager()
 */
class ProductBadgeFacade extends AbstractFacade implements ProductBadgeFacadeInterface
{
    /**
     * {@inheritDoc}
     *
     * @api
     *
     * @param \Generated\Shared\Transfer\ProductBadgeTransfer $productBadgeTransfer
     *
     * @return \Generated\Shared\Transfer\ProductBadgeTransfer
     */
    public function createProductBadge(ProductBadgeTransfer $productBadgeTransfer): ProductBadgeTransfer
    {
        return $this->getFactory()
            ->createProductBadgeCreator()
            ->createProductBadge($productBadgeTransfer);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     *
     * @param \Generated\Shared\Transfer\ProductBadgeCriteriaTransfer $productBadgeCriteriaTransfer
     *
     * @return \Generated\Shared\Transfer\ProductBadgeCollectionTransfer
     */
    public function getProductBadgeCollection(ProductBadgeCriteriaTransfer $productBadgeCriteriaTransfer): ProductBadgeCollectionTransfer
    {
        return $this->getFactory()
            ->createProductBadgeReader()
            ->getProductBadgeCollection($productBadgeCriteriaTransfer);
    }
}
