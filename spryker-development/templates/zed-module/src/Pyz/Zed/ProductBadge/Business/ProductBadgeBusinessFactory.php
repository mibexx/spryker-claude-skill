<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadge\Business;

use Pyz\Zed\ProductBadge\Business\Creator\ProductBadgeCreator;
use Pyz\Zed\ProductBadge\Business\Creator\ProductBadgeCreatorInterface;
use Pyz\Zed\ProductBadge\Business\Reader\ProductBadgeReader;
use Pyz\Zed\ProductBadge\Business\Reader\ProductBadgeReaderInterface;
use Pyz\Zed\ProductBadge\Business\Validator\ProductBadgeValidator;
use Pyz\Zed\ProductBadge\Business\Validator\ProductBadgeValidatorInterface;
use Pyz\Zed\ProductBadge\ProductBadgeDependencyProvider;
use Spryker\Zed\Kernel\Business\AbstractBusinessFactory;
use Spryker\Zed\Product\Business\ProductFacadeInterface;

/**
 * @method \Pyz\Zed\ProductBadge\ProductBadgeConfig getConfig()
 * @method \Pyz\Zed\ProductBadge\Persistence\ProductBadgeRepositoryInterface getRepository()
 * @method \Pyz\Zed\ProductBadge\Persistence\ProductBadgeEntityManagerInterface getEntityManager()
 */
class ProductBadgeBusinessFactory extends AbstractBusinessFactory
{
    public function createProductBadgeCreator(): ProductBadgeCreatorInterface
    {
        return new ProductBadgeCreator(
            $this->createProductBadgeValidator(),
            $this->getEntityManager(),
        );
    }

    public function createProductBadgeReader(): ProductBadgeReaderInterface
    {
        return new ProductBadgeReader(
            $this->getRepository(),
        );
    }

    public function createProductBadgeValidator(): ProductBadgeValidatorInterface
    {
        return new ProductBadgeValidator(
            $this->getProductFacade(),
            $this->getConfig(),
        );
    }

    public function getProductFacade(): ProductFacadeInterface
    {
        return $this->getProvidedDependency(ProductBadgeDependencyProvider::FACADE_PRODUCT);
    }
}
