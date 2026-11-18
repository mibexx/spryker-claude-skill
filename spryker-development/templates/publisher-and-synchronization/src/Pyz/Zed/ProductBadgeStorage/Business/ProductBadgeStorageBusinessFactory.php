<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadgeStorage\Business;

use Pyz\Zed\ProductBadgeStorage\Business\Writer\ProductBadgeStorageWriter;
use Pyz\Zed\ProductBadgeStorage\Business\Writer\ProductBadgeStorageWriterInterface;
use Pyz\Zed\ProductBadgeStorage\ProductBadgeStorageDependencyProvider;
use Spryker\Zed\EventBehavior\Business\EventBehaviorFacadeInterface;
use Spryker\Zed\Kernel\Business\AbstractBusinessFactory;

/**
 * @method \Pyz\Zed\ProductBadgeStorage\Persistence\ProductBadgeStorageRepositoryInterface getRepository()
 * @method \Pyz\Zed\ProductBadgeStorage\Persistence\ProductBadgeStorageEntityManagerInterface getEntityManager()
 */
class ProductBadgeStorageBusinessFactory extends AbstractBusinessFactory
{
    public function createProductBadgeStorageWriter(): ProductBadgeStorageWriterInterface
    {
        return new ProductBadgeStorageWriter(
            $this->getEventBehaviorFacade(),
            $this->getRepository(),
            $this->getEntityManager(),
        );
    }

    public function getEventBehaviorFacade(): EventBehaviorFacadeInterface
    {
        return $this->getProvidedDependency(ProductBadgeStorageDependencyProvider::FACADE_EVENT_BEHAVIOR);
    }
}
