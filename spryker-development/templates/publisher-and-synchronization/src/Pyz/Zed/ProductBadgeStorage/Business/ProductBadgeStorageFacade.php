<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadgeStorage\Business;

use Spryker\Zed\Kernel\Business\AbstractFacade;

/**
 * @method \Pyz\Zed\ProductBadgeStorage\Business\ProductBadgeStorageBusinessFactory getFactory()
 * @method \Pyz\Zed\ProductBadgeStorage\Persistence\ProductBadgeStorageRepositoryInterface getRepository()
 * @method \Pyz\Zed\ProductBadgeStorage\Persistence\ProductBadgeStorageEntityManagerInterface getEntityManager()
 */
class ProductBadgeStorageFacade extends AbstractFacade implements ProductBadgeStorageFacadeInterface
{
    /**
     * {@inheritDoc}
     *
     * @api
     *
     * @param array<\Generated\Shared\Transfer\EventEntityTransfer> $eventEntityTransfers
     * @param string $eventName
     *
     * @return void
     */
    public function writeProductBadgeStorageCollectionByEvents(array $eventEntityTransfers, string $eventName): void
    {
        $this->getFactory()
            ->createProductBadgeStorageWriter()
            ->writeCollectionByProductBadgeEvents($eventEntityTransfers, $eventName);
    }
}
