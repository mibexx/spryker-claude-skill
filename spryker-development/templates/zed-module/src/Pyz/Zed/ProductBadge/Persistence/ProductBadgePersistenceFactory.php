<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadge\Persistence;

use Orm\Zed\ProductBadge\Persistence\SpyProductBadge;
use Orm\Zed\ProductBadge\Persistence\SpyProductBadgeQuery;
use Pyz\Zed\ProductBadge\Persistence\Propel\Mapper\ProductBadgeMapper;
use Spryker\Zed\Kernel\Persistence\AbstractPersistenceFactory;

/**
 * @method \Pyz\Zed\ProductBadge\ProductBadgeConfig getConfig()
 * @method \Pyz\Zed\ProductBadge\Persistence\ProductBadgeRepositoryInterface getRepository()
 * @method \Pyz\Zed\ProductBadge\Persistence\ProductBadgeEntityManagerInterface getEntityManager()
 */
class ProductBadgePersistenceFactory extends AbstractPersistenceFactory
{
    public function createProductBadgeQuery(): SpyProductBadgeQuery
    {
        return SpyProductBadgeQuery::create();
    }

    public function createProductBadgeEntity(): SpyProductBadge
    {
        return new SpyProductBadge();
    }

    public function createProductBadgeMapper(): ProductBadgeMapper
    {
        return new ProductBadgeMapper();
    }
}
