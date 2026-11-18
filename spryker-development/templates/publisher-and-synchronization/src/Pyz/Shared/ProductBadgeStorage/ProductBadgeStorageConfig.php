<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Shared\ProductBadgeStorage;

use Spryker\Shared\Kernel\AbstractBundleConfig;

class ProductBadgeStorageConfig extends AbstractBundleConfig
{
    /**
     * Specification:
     * - Resource name, used for key generation. Must match the `resource` parameter of the synchronization behavior.
     */
    public const string PRODUCT_BADGE_RESOURCE_NAME = 'product_badge';

    /**
     * Specification:
     * - Queue name for synchronizing spy_product_badge_storage to Redis. Must match the `queue_group` parameter of the synchronization behavior.
     */
    public const string PRODUCT_BADGE_SYNC_STORAGE_QUEUE = 'sync.storage.product_badge';

    /**
     * Specification:
     * - Queue pool for synchronizing spy_product_badge_storage. Must match the `queue_pool` parameter of the synchronization behavior
     *   and the pool in `RabbitMqConfig::getQueuePools()`. Without a store column `sync:data` needs it (SynchronizationQueuePoolNotFoundException).
     */
    public const string PRODUCT_BADGE_SYNCHRONIZATION_POOL_NAME = 'synchronizationPool';

    /**
     * Specification:
     * - Queue name for publishing product badge events to the writer.
     */
    public const string PUBLISH_PRODUCT_BADGE = 'publish.product_badge';

    /**
     * Specification:
     * - Event name, emitted by the event behavior of spy_product_badge.
     */
    public const string ENTITY_SPY_PRODUCT_BADGE_CREATE = 'Entity.spy_product_badge.create';

    public const string ENTITY_SPY_PRODUCT_BADGE_UPDATE = 'Entity.spy_product_badge.update';

    public const string ENTITY_SPY_PRODUCT_BADGE_DELETE = 'Entity.spy_product_badge.delete';

    /**
     * Specification:
     * - Event name for manual re-generation (publish:trigger-events -r product_badge). Event ids are product abstract ids.
     */
    public const string PRODUCT_BADGE_PUBLISH = 'ProductBadge.product_badge.publish';
}
