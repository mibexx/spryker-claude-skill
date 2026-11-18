<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadge;

use Spryker\Zed\Kernel\AbstractBundleDependencyProvider;
use Spryker\Zed\Kernel\Container;

/**
 * @method \Pyz\Zed\ProductBadge\ProductBadgeConfig getConfig()
 */
class ProductBadgeDependencyProvider extends AbstractBundleDependencyProvider
{
    public const FACADE_PRODUCT = 'FACADE_PRODUCT';

    public function provideBusinessLayerDependencies(Container $container): Container
    {
        $container = parent::provideBusinessLayerDependencies($container);
        $container = $this->addProductFacade($container);

        return $container;
    }

    protected function addProductFacade(Container $container): Container
    {
        $container->set(static::FACADE_PRODUCT, function (Container $container) {
            return $container->getLocator()->product()->facade();
        });

        return $container;
    }
}
