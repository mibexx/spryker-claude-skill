<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Client\ProductBadge;

use Pyz\Client\ProductBadge\Reader\ProductBadgeStorageReader;
use Pyz\Client\ProductBadge\Reader\ProductBadgeStorageReaderInterface;
use Spryker\Client\Kernel\AbstractFactory;
use Spryker\Client\Storage\StorageClientInterface;
use Spryker\Service\Synchronization\SynchronizationServiceInterface;

class ProductBadgeFactory extends AbstractFactory
{
    public function createProductBadgeStorageReader(): ProductBadgeStorageReaderInterface
    {
        return new ProductBadgeStorageReader(
            $this->getStorageClient(),
            $this->getSynchronizationService(),
        );
    }

    public function getStorageClient(): StorageClientInterface
    {
        return $this->getProvidedDependency(ProductBadgeDependencyProvider::CLIENT_STORAGE);
    }

    public function getSynchronizationService(): SynchronizationServiceInterface
    {
        return $this->getProvidedDependency(ProductBadgeDependencyProvider::SERVICE_SYNCHRONIZATION);
    }
}
