<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Glue\ProductBadgesBackendApi\Plugin\GlueBackendApiApplication;

use Generated\Shared\Transfer\GlueResourceMethodCollectionTransfer;
use Generated\Shared\Transfer\GlueResourceMethodConfigurationTransfer;
use Generated\Shared\Transfer\ProductBadgesBackendApiAttributesTransfer;
use Pyz\Glue\ProductBadgesBackendApi\Controller\ProductBadgesResourceController;
use Pyz\Glue\ProductBadgesBackendApi\ProductBadgesBackendApiConfig;
use Spryker\Glue\GlueApplication\Plugin\GlueApplication\Backend\AbstractResourcePlugin;
use Spryker\Glue\GlueApplicationExtension\Dependency\Plugin\ResourceInterface;

class ProductBadgesBackendResource extends AbstractResourcePlugin implements ResourceInterface
{
    /**
     * {@inheritDoc}
     *
     * @api
     */
    public function getType(): string
    {
        return ProductBadgesBackendApiConfig::RESOURCE_PRODUCT_BADGES;
    }

    /**
     * {@inheritDoc}
     *
     * @api
     */
    public function getController(): string
    {
        return ProductBadgesResourceController::class;
    }

    /**
     * {@inheritDoc}
     *
     * @api
     */
    public function getDeclaredMethods(): GlueResourceMethodCollectionTransfer
    {
        return (new GlueResourceMethodCollectionTransfer())
            ->setGetCollection(
                (new GlueResourceMethodConfigurationTransfer())
                    ->setAction('getCollectionAction')
                    ->setAttributes(ProductBadgesBackendApiAttributesTransfer::class)
                    ->setIsSingularResponse(false),
            )
            ->setPost(
                (new GlueResourceMethodConfigurationTransfer())
                    ->setAction('postAction')
                    ->setAttributes(ProductBadgesBackendApiAttributesTransfer::class)
                    ->setIsSingularResponse(true),
            );
    }
}
