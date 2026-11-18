<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Glue\ProductBadgesBackendApi;

use Spryker\Glue\Kernel\Backend\AbstractBundleDependencyProvider;
use Spryker\Glue\Kernel\Backend\Container;

/**
 * @method \Pyz\Glue\ProductBadgesBackendApi\ProductBadgesBackendApiConfig getConfig()
 */
class ProductBadgesBackendApiDependencyProvider extends AbstractBundleDependencyProvider
{
    public const FACADE_PRODUCT_BADGE = 'FACADE_PRODUCT_BADGE';

    public function provideBackendDependencies(Container $container): Container
    {
        $container = parent::provideBackendDependencies($container);
        $container = $this->addProductBadgeFacade($container);

        return $container;
    }

    protected function addProductBadgeFacade(Container $container): Container
    {
        $container->set(static::FACADE_PRODUCT_BADGE, function (Container $container) {
            return $container->getLocator()->productBadge()->facade();
        });

        return $container;
    }
}
