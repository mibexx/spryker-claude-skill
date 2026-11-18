<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadgeStorage\Persistence;

use Orm\Zed\ProductBadge\Persistence\SpyProductBadgeQuery;
use Orm\Zed\ProductBadgeStorage\Persistence\SpyProductBadgeStorageQuery;
use Pyz\Zed\ProductBadgeStorage\Persistence\Propel\Mapper\ProductBadgeStorageMapper;
use Pyz\Zed\ProductBadgeStorage\ProductBadgeStorageDependencyProvider;
use Spryker\Zed\Kernel\Persistence\AbstractPersistenceFactory;

/**
 * @method \Pyz\Zed\ProductBadgeStorage\Persistence\ProductBadgeStorageRepositoryInterface getRepository()
 * @method \Pyz\Zed\ProductBadgeStorage\Persistence\ProductBadgeStorageEntityManagerInterface getEntityManager()
 */
class ProductBadgeStoragePersistenceFactory extends AbstractPersistenceFactory
{
    public function createProductBadgeStorageQuery(): SpyProductBadgeStorageQuery
    {
        return SpyProductBadgeStorageQuery::create();
    }

    public function getProductBadgePropelQuery(): SpyProductBadgeQuery
    {
        return $this->getProvidedDependency(ProductBadgeStorageDependencyProvider::PROPEL_QUERY_PRODUCT_BADGE);
    }

    public function createProductBadgeStorageMapper(): ProductBadgeStorageMapper
    {
        return new ProductBadgeStorageMapper();
    }
}
