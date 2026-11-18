<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadgeStorage\Persistence;

use Generated\Shared\Transfer\ProductBadgeStorageTransfer;
use Spryker\Zed\Kernel\Persistence\AbstractEntityManager;

/**
 * @method \Pyz\Zed\ProductBadgeStorage\Persistence\ProductBadgeStoragePersistenceFactory getFactory()
 */
class ProductBadgeStorageEntityManager extends AbstractEntityManager implements ProductBadgeStorageEntityManagerInterface
{
    public function saveProductBadgeStorage(ProductBadgeStorageTransfer $productBadgeStorageTransfer): void
    {
        $productBadgeStorageEntity = $this->getFactory()
            ->createProductBadgeStorageQuery()
            ->filterByFkProductAbstract($productBadgeStorageTransfer->getIdProductAbstractOrFail())
            ->findOneOrCreate();

        // The synchronization behavior json-encodes the array, adds `_timestamp`, generates the key and sends the sync message in postSave().
        $productBadgeStorageEntity->setData($productBadgeStorageTransfer->toArray());
        $productBadgeStorageEntity->save();
    }

    public function deleteProductBadgeStorageByIdProductAbstract(int $idProductAbstract): void
    {
        $productBadgeStorageEntity = $this->getFactory()
            ->createProductBadgeStorageQuery()
            ->filterByFkProductAbstract($idProductAbstract)
            ->findOne();

        if ($productBadgeStorageEntity === null) {
            return;
        }

        // Entity::delete() (not a bulk delete) is required: postDelete() of the behavior removes the Redis key.
        $productBadgeStorageEntity->delete();
    }
}
