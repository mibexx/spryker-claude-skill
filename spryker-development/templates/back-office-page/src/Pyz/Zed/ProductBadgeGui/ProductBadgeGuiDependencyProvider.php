<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadgeGui;

use Orm\Zed\ProductBadge\Persistence\SpyProductBadgeQuery;
use Spryker\Zed\Kernel\AbstractBundleDependencyProvider;
use Spryker\Zed\Kernel\Container;

/**
 * @method \Pyz\Zed\ProductBadgeGui\ProductBadgeGuiConfig getConfig()
 */
class ProductBadgeGuiDependencyProvider extends AbstractBundleDependencyProvider
{
    public const FACADE_PRODUCT_BADGE = 'FACADE_PRODUCT_BADGE';

    public const PROPEL_QUERY_PRODUCT_BADGE = 'PROPEL_QUERY_PRODUCT_BADGE';

    public function provideCommunicationLayerDependencies(Container $container): Container
    {
        $container = parent::provideCommunicationLayerDependencies($container);

        $container = $this->addProductBadgeFacade($container);
        $container = $this->addProductBadgePropelQuery($container);

        return $container;
    }

    protected function addProductBadgeFacade(Container $container): Container
    {
        $container->set(static::FACADE_PRODUCT_BADGE, function (Container $container) {
            return $container->getLocator()->productBadge()->facade();
        });

        return $container;
    }

    protected function addProductBadgePropelQuery(Container $container): Container
    {
        $container->set(static::PROPEL_QUERY_PRODUCT_BADGE, $container->factory(function () {
            return SpyProductBadgeQuery::create();
        }));

        return $container;
    }
}
