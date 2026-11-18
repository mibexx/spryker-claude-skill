<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadgeStorage;

use Orm\Zed\ProductBadge\Persistence\SpyProductBadgeQuery;
use Spryker\Zed\Kernel\AbstractBundleDependencyProvider;
use Spryker\Zed\Kernel\Container;

class ProductBadgeStorageDependencyProvider extends AbstractBundleDependencyProvider
{
    public const string FACADE_EVENT_BEHAVIOR = 'FACADE_EVENT_BEHAVIOR';

    public const string PROPEL_QUERY_PRODUCT_BADGE = 'PROPEL_QUERY_PRODUCT_BADGE';

    public function provideBusinessLayerDependencies(Container $container): Container
    {
        $container = parent::provideBusinessLayerDependencies($container);
        $container = $this->addEventBehaviorFacade($container);

        return $container;
    }

    public function providePersistenceLayerDependencies(Container $container): Container
    {
        $container = parent::providePersistenceLayerDependencies($container);
        $container = $this->addProductBadgeQuery($container);

        return $container;
    }

    protected function addEventBehaviorFacade(Container $container): Container
    {
        $container->set(static::FACADE_EVENT_BEHAVIOR, function (Container $container) {
            return $container->getLocator()->eventBehavior()->facade();
        });

        return $container;
    }

    protected function addProductBadgeQuery(Container $container): Container
    {
        $container->set(static::PROPEL_QUERY_PRODUCT_BADGE, $container->factory(function (): SpyProductBadgeQuery {
            return SpyProductBadgeQuery::create();
        }));

        return $container;
    }
}
