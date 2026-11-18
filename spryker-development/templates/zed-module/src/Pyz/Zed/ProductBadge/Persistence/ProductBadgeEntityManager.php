<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadge\Persistence;

use Generated\Shared\Transfer\ProductBadgeTransfer;
use Spryker\Zed\Kernel\Persistence\AbstractEntityManager;

/**
 * @method \Pyz\Zed\ProductBadge\Persistence\ProductBadgePersistenceFactory getFactory()
 */
class ProductBadgeEntityManager extends AbstractEntityManager implements ProductBadgeEntityManagerInterface
{
    public function createProductBadge(ProductBadgeTransfer $productBadgeTransfer): ProductBadgeTransfer
    {
        $productBadgeMapper = $this->getFactory()->createProductBadgeMapper();

        $productBadgeEntity = $productBadgeMapper->mapProductBadgeTransferToProductBadgeEntity(
            $productBadgeTransfer,
            $this->getFactory()->createProductBadgeEntity(),
        );
        $productBadgeEntity->save();

        return $productBadgeMapper->mapProductBadgeEntityToProductBadgeTransfer(
            $productBadgeEntity,
            $productBadgeTransfer,
        );
    }
}
