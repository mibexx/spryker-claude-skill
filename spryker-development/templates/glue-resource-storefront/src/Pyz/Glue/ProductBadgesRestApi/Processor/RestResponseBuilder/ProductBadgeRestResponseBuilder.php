<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Glue\ProductBadgesRestApi\Processor\RestResponseBuilder;

use Generated\Shared\Transfer\RestErrorMessageTransfer;
use Pyz\Glue\ProductBadgesRestApi\ProductBadgesRestApiConfig;
use Spryker\Glue\GlueApplication\Rest\JsonApi\RestResourceBuilderInterface;
use Spryker\Glue\GlueApplication\Rest\JsonApi\RestResponseInterface;
use Symfony\Component\HttpFoundation\Response;

class ProductBadgeRestResponseBuilder implements ProductBadgeRestResponseBuilderInterface
{
    public function __construct(protected RestResourceBuilderInterface $restResourceBuilder)
    {
    }

    /**
     * @param array<string, \Generated\Shared\Transfer\RestProductBadgesAttributesTransfer> $restProductBadgesAttributesTransfers
     */
    public function createProductBadgesRestResponse(array $restProductBadgesAttributesTransfers): RestResponseInterface
    {
        $restResponse = $this->restResourceBuilder->createRestResponse();

        foreach ($restProductBadgesAttributesTransfers as $badgeKey => $restProductBadgesAttributesTransfer) {
            $restResponse->addResource(
                $this->restResourceBuilder->createRestResource(
                    ProductBadgesRestApiConfig::RESOURCE_PRODUCT_BADGES,
                    (string)$badgeKey,
                    $restProductBadgesAttributesTransfer,
                ),
            );
        }

        return $restResponse;
    }

    public function createMissingIdProductAbstractErrorResponse(): RestResponseInterface
    {
        return $this->restResourceBuilder->createRestResponse()->addError(
            (new RestErrorMessageTransfer())
                ->setStatus(Response::HTTP_BAD_REQUEST)
                ->setCode(ProductBadgesRestApiConfig::RESPONSE_CODE_MISSING_ID_PRODUCT_ABSTRACT)
                ->setDetail(ProductBadgesRestApiConfig::RESPONSE_DETAIL_MISSING_ID_PRODUCT_ABSTRACT),
        );
    }

    public function createProductBadgeNotFoundErrorResponse(): RestResponseInterface
    {
        return $this->restResourceBuilder->createRestResponse()->addError(
            (new RestErrorMessageTransfer())
                ->setStatus(Response::HTTP_NOT_FOUND)
                ->setCode(ProductBadgesRestApiConfig::RESPONSE_CODE_PRODUCT_BADGE_NOT_FOUND)
                ->setDetail(ProductBadgesRestApiConfig::RESPONSE_DETAIL_PRODUCT_BADGE_NOT_FOUND),
        );
    }
}
