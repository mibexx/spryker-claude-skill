<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Glue\ProductBadgesRestApi\Processor\Reader;

use Pyz\Client\ProductBadge\ProductBadgeClientInterface;
use Pyz\Glue\ProductBadgesRestApi\Processor\Mapper\ProductBadgeMapperInterface;
use Pyz\Glue\ProductBadgesRestApi\Processor\RestResponseBuilder\ProductBadgeRestResponseBuilderInterface;
use Pyz\Glue\ProductBadgesRestApi\ProductBadgesRestApiConfig;
use Spryker\Glue\GlueApplication\Rest\JsonApi\RestResponseInterface;
use Spryker\Glue\GlueApplication\Rest\Request\Data\RestRequestInterface;

class ProductBadgeReader implements ProductBadgeReaderInterface
{
    public function __construct(
        protected ProductBadgeClientInterface $productBadgeClient,
        protected ProductBadgeMapperInterface $productBadgeMapper,
        protected ProductBadgeRestResponseBuilderInterface $productBadgeRestResponseBuilder,
    ) {
    }

    public function getProductBadges(RestRequestInterface $restRequest): RestResponseInterface
    {
        $idProductAbstract = (int)$restRequest
            ->getHttpRequest()
            ->query
            ->get(ProductBadgesRestApiConfig::PARAMETER_ID_PRODUCT_ABSTRACT);

        if ($idProductAbstract <= 0) {
            return $this->productBadgeRestResponseBuilder->createMissingIdProductAbstractErrorResponse();
        }

        $restProductBadgesAttributesTransfers = $this->productBadgeMapper->mapProductBadgesToRestProductBadgesAttributesTransfers(
            $this->productBadgeClient->findProductBadgesByIdProductAbstract($idProductAbstract),
        );

        $badgeKey = $restRequest->getResource()->getId();
        if ($badgeKey === null) {
            return $this->productBadgeRestResponseBuilder->createProductBadgesRestResponse($restProductBadgesAttributesTransfers);
        }

        if (!isset($restProductBadgesAttributesTransfers[$badgeKey])) {
            return $this->productBadgeRestResponseBuilder->createProductBadgeNotFoundErrorResponse();
        }

        return $this->productBadgeRestResponseBuilder->createProductBadgesRestResponse(
            [$badgeKey => $restProductBadgesAttributesTransfers[$badgeKey]],
        );
    }
}
