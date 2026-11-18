<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Glue\ProductBadgesBackendApi\Processor\Reader;

use Generated\Shared\Transfer\GlueRequestTransfer;
use Generated\Shared\Transfer\GlueResourceTransfer;
use Generated\Shared\Transfer\GlueResponseTransfer;
use Generated\Shared\Transfer\ProductBadgeCriteriaTransfer;
use Pyz\Glue\ProductBadgesBackendApi\Processor\Mapper\ProductBadgeMapperInterface;
use Pyz\Glue\ProductBadgesBackendApi\ProductBadgesBackendApiConfig;
use Pyz\Zed\ProductBadge\Business\ProductBadgeFacadeInterface;
use Symfony\Component\HttpFoundation\Response;

class ProductBadgeReader implements ProductBadgeReaderInterface
{
    public function __construct(
        protected ProductBadgeFacadeInterface $productBadgeFacade,
        protected ProductBadgeMapperInterface $productBadgeMapper,
    ) {
    }

    public function getProductBadgeCollection(GlueRequestTransfer $glueRequestTransfer): GlueResponseTransfer
    {
        $productBadgeCriteriaTransfer = new ProductBadgeCriteriaTransfer();

        $queryFields = $glueRequestTransfer->getQueryFields();
        if (isset($queryFields[ProductBadgesBackendApiConfig::QUERY_PARAMETER_BADGE_KEY])) {
            $productBadgeCriteriaTransfer->addBadgeKey((string)$queryFields[ProductBadgesBackendApiConfig::QUERY_PARAMETER_BADGE_KEY]);
        }

        $productBadgeCollectionTransfer = $this->productBadgeFacade->getProductBadgeCollection($productBadgeCriteriaTransfer);

        $glueResponseTransfer = (new GlueResponseTransfer())->setHttpStatus(Response::HTTP_OK);
        foreach ($productBadgeCollectionTransfer->getProductBadges() as $productBadgeTransfer) {
            $glueResponseTransfer->addResource(
                $this->productBadgeMapper->mapProductBadgeTransferToGlueResourceTransfer(
                    $productBadgeTransfer,
                    new GlueResourceTransfer(),
                ),
            );
        }

        return $glueResponseTransfer;
    }
}
