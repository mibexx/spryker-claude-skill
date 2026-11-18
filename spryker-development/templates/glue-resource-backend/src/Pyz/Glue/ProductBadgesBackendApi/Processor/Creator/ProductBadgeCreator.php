<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Glue\ProductBadgesBackendApi\Processor\Creator;

use Generated\Shared\Transfer\GlueErrorTransfer;
use Generated\Shared\Transfer\GlueResourceTransfer;
use Generated\Shared\Transfer\GlueResponseTransfer;
use Generated\Shared\Transfer\ProductBadgesBackendApiAttributesTransfer;
use Generated\Shared\Transfer\ProductBadgeTransfer;
use Pyz\Glue\ProductBadgesBackendApi\Processor\Mapper\ProductBadgeMapperInterface;
use Pyz\Glue\ProductBadgesBackendApi\ProductBadgesBackendApiConfig;
use Pyz\Zed\ProductBadge\Business\Exception\InvalidProductBadgeException;
use Pyz\Zed\ProductBadge\Business\ProductBadgeFacadeInterface;
use Symfony\Component\HttpFoundation\Response;

class ProductBadgeCreator implements ProductBadgeCreatorInterface
{
    public function __construct(
        protected ProductBadgeFacadeInterface $productBadgeFacade,
        protected ProductBadgeMapperInterface $productBadgeMapper,
        protected ProductBadgesBackendApiConfig $productBadgesBackendApiConfig,
    ) {
    }

    public function createProductBadge(ProductBadgesBackendApiAttributesTransfer $attributesTransfer): GlueResponseTransfer
    {
        if (!$attributesTransfer->getFkProductAbstract() || !$attributesTransfer->getBadgeKey() || !$attributesTransfer->getLabel()) {
            return $this->createInvalidProductBadgeResponse();
        }

        $productBadgeTransfer = $this->productBadgeMapper->mapAttributesToProductBadgeTransfer(
            $attributesTransfer,
            new ProductBadgeTransfer(),
        );

        try {
            $productBadgeTransfer = $this->productBadgeFacade->createProductBadge($productBadgeTransfer);
        } catch (InvalidProductBadgeException $exception) {
            return $this->createUnprocessableProductBadgeResponse($exception->getMessage());
        }

        return (new GlueResponseTransfer())
            ->setHttpStatus(Response::HTTP_CREATED)
            ->addResource(
                $this->productBadgeMapper->mapProductBadgeTransferToGlueResourceTransfer(
                    $productBadgeTransfer,
                    new GlueResourceTransfer(),
                ),
            );
    }

    protected function createInvalidProductBadgeResponse(): GlueResponseTransfer
    {
        return (new GlueResponseTransfer())
            ->setHttpStatus(Response::HTTP_BAD_REQUEST)
            ->addError(
                (new GlueErrorTransfer())
                    ->setStatus(Response::HTTP_BAD_REQUEST)
                    ->setCode(ProductBadgesBackendApiConfig::ERROR_CODE_INVALID_PRODUCT_BADGE)
                    ->setMessage(ProductBadgesBackendApiConfig::ERROR_MESSAGE_INVALID_PRODUCT_BADGE),
            );
    }

    protected function createUnprocessableProductBadgeResponse(string $message): GlueResponseTransfer
    {
        return (new GlueResponseTransfer())
            ->setHttpStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->addError(
                (new GlueErrorTransfer())
                    ->setStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
                    ->setCode(ProductBadgesBackendApiConfig::ERROR_CODE_UNPROCESSABLE_PRODUCT_BADGE)
                    ->setMessage($message),
            );
    }
}
