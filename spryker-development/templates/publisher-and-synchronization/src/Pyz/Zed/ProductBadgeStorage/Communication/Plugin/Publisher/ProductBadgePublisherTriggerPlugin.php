<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadgeStorage\Communication\Plugin\Publisher;

use Pyz\Shared\ProductBadgeStorage\ProductBadgeStorageConfig;
use Spryker\Zed\Kernel\Communication\AbstractPlugin;
use Spryker\Zed\PublisherExtension\Dependency\Plugin\PublisherTriggerPluginInterface;

/**
 * @method \Pyz\Zed\ProductBadgeStorage\Persistence\ProductBadgeStorageRepositoryInterface getRepository()
 * @method \Pyz\Zed\ProductBadgeStorage\Business\ProductBadgeStorageFacadeInterface getFacade()
 */
class ProductBadgePublisherTriggerPlugin extends AbstractPlugin implements PublisherTriggerPluginInterface
{
    protected const string COL_FK_PRODUCT_ABSTRACT = 'spy_product_badge.fk_product_abstract';

    /**
     * {@inheritDoc}
     * - Retrieves product badge entity transfers by offset and limit. The event ID is read from their `fk_product_abstract` (see getIdColumnName()).
     *
     * @api
     *
     * @param int $offset
     * @param int $limit
     *
     * @return array<\Generated\Shared\Transfer\SpyProductBadgeEntityTransfer>
     */
    public function getData(int $offset, int $limit): array
    {
        return $this->getRepository()->getProductBadgeEntityTransfers($offset, $limit);
    }

    /**
     * {@inheritDoc}
     * - Must equal the `resource` parameter of the synchronization behavior.
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
     *
     * @api
     *
     * @return string
     */
    public function getEventName(): string
    {
        return ProductBadgeStorageConfig::PRODUCT_BADGE_PUBLISH;
    }

    /**
     * {@inheritDoc}
     *
     * @api
     *
     * @return string|null
     */
    public function getIdColumnName(): ?string
    {
        return static::COL_FK_PRODUCT_ABSTRACT;
    }
}
