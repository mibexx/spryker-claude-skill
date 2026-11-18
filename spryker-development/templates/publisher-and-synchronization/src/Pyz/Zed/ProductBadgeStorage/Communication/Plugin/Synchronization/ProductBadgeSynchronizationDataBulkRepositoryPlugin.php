<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadgeStorage\Communication\Plugin\Synchronization;

use Pyz\Shared\ProductBadgeStorage\ProductBadgeStorageConfig;
use Spryker\Zed\Kernel\Communication\AbstractPlugin;
use Spryker\Zed\SynchronizationExtension\Dependency\Plugin\SynchronizationDataBulkRepositoryPluginInterface;

/**
 * @method \Pyz\Zed\ProductBadgeStorage\Persistence\ProductBadgeStorageRepositoryInterface getRepository()
 * @method \Pyz\Zed\ProductBadgeStorage\Business\ProductBadgeStorageFacadeInterface getFacade()
 */
class ProductBadgeSynchronizationDataBulkRepositoryPlugin extends AbstractPlugin implements SynchronizationDataBulkRepositoryPluginInterface
{
    /**
     * {@inheritDoc}
     *
     * @api
     *
     * @return string
     */
    public function getResourceName(): string
    {
        return ProductBadgeStorageConfig::PRODUCT_BADGE_RESOURCE_NAME;
    }

    /**
     * {@inheritDoc}
     * - The storage key has no store part (no `store` parameter in the synchronization behavior).
     *
     * @api
     *
     * @return bool
     */
    public function hasStore(): bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     * - Reads `spy_product_badge_storage` (key and data) for `sync:data product_badge [ids]`. IDs are product abstract IDs.
     *
     * @api
     *
     * @param int $offset
     * @param int $limit
     * @param array<int> $ids
     *
     * @return array<\Generated\Shared\Transfer\SynchronizationDataTransfer>
     */
    public function getData(int $offset, int $limit, array $ids = []): array
    {
        return $this->getRepository()->getSynchronizationDataTransfers($offset, $limit, $ids);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     *
     * @return array<string, mixed>
     */
    public function getParams(): array
    {
        return [];
    }

    /**
     * {@inheritDoc}
     *
     * @api
     *
     * @return string
     */
    public function getQueueName(): string
    {
        return ProductBadgeStorageConfig::PRODUCT_BADGE_SYNC_STORAGE_QUEUE;
    }

    /**
     * {@inheritDoc}
     * - Queue pool of the table (`queue_pool` parameter of the synchronization behavior), required by `sync:data` for keys without store.
     *
     * @api
     *
     * @return string|null
     */
    public function getSynchronizationQueuePoolName(): ?string
    {
        return ProductBadgeStorageConfig::PRODUCT_BADGE_SYNCHRONIZATION_POOL_NAME;
    }
}
