<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadgeStorage\Communication\Plugin\Publisher;

use Pyz\Shared\ProductBadgeStorage\ProductBadgeStorageConfig;
use Spryker\Zed\Kernel\Communication\AbstractPlugin;
use Spryker\Zed\PublisherExtension\Dependency\Plugin\PublisherPluginInterface;

/**
 * @method \Pyz\Zed\ProductBadgeStorage\Business\ProductBadgeStorageFacadeInterface getFacade()
 */
class ProductBadgeWritePublisherPlugin extends AbstractPlugin implements PublisherPluginInterface
{
    /**
     * {@inheritDoc}
     * - Writes the product badge storage entities of all affected product abstracts.
     *
     * @api
     *
     * @param array<\Generated\Shared\Transfer\EventEntityTransfer> $eventEntityTransfers
     * @param string $eventName
     *
     * @return void
     */
    public function handleBulk(array $eventEntityTransfers, $eventName): void
    {
        $this->getFacade()->writeProductBadgeStorageCollectionByEvents($eventEntityTransfers, (string)$eventName);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     *
     * @return array<string>
     */
    public function getSubscribedEvents(): array
    {
        return [
            ProductBadgeStorageConfig::ENTITY_SPY_PRODUCT_BADGE_CREATE,
            ProductBadgeStorageConfig::ENTITY_SPY_PRODUCT_BADGE_UPDATE,
            ProductBadgeStorageConfig::ENTITY_SPY_PRODUCT_BADGE_DELETE,
            ProductBadgeStorageConfig::PRODUCT_BADGE_PUBLISH,
        ];
    }
}
