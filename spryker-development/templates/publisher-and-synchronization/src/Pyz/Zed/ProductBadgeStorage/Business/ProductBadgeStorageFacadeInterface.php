<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadgeStorage\Business;

interface ProductBadgeStorageFacadeInterface
{
    /**
     * Specification:
     * - Resolves the affected product abstract IDs from the given events.
     * - Uses the foreign key `spy_product_badge.fk_product_abstract` for Entity events and the event ID for the publish event.
     * - Writes one `spy_product_badge_storage` entity per product abstract, containing all its badges.
     * - Deletes the storage entity of a product abstract that has no badges left.
     * - Saving or deleting the storage entity triggers the synchronization behavior (Redis key `product_badge:<id_product_abstract>`).
     *
     * @api
     *
     * @param array<\Generated\Shared\Transfer\EventEntityTransfer> $eventEntityTransfers
     * @param string $eventName
     */
    public function writeProductBadgeStorageCollectionByEvents(array $eventEntityTransfers, string $eventName): void;
}
