<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Glue\ProductBadgesBackendApi\Controller;

use Generated\Shared\Transfer\GlueRequestTransfer;
use Generated\Shared\Transfer\GlueResponseTransfer;
use Generated\Shared\Transfer\ProductBadgesBackendApiAttributesTransfer;
use Spryker\Glue\Kernel\Backend\Controller\AbstractBackendApiController;

/**
 * @method \Pyz\Glue\ProductBadgesBackendApi\ProductBadgesBackendApiFactory getFactory()
 */
class ProductBadgesResourceController extends AbstractBackendApiController
{
    /**
     * @Glue({
     *     "getCollection": {
     *          "summary": [
     *              "Retrieves product badges."
     *          ],
     *          "parameters": [
     *              {"ref": "acceptLanguage"},
     *              {"name": "badgeKey", "in": "query", "required": false, "description": "Filters by badge key."}
     *          ],
     *          "responseAttributesClassName": "Generated\\Shared\\Transfer\\ProductBadgesBackendApiAttributesTransfer",
     *          "responses": {
     *              "403": "Forbidden"
     *          }
     *     },
     *     "post": {
     *          "summary": [
     *              "Creates a product badge."
     *          ],
     *          "parameters": [{"ref": "acceptLanguage"}],
     *          "requestAttributesClassName": "Generated\\Shared\\Transfer\\ProductBadgesBackendApiAttributesTransfer",
     *          "responseAttributesClassName": "Generated\\Shared\\Transfer\\ProductBadgesBackendApiAttributesTransfer",
     *          "responses": {
     *              "400": "Bad request",
     *              "403": "Forbidden",
     *              "422": "Invalid badge key or unknown abstract product"
     *          }
     *     }
     * })
     *
     * @param \Generated\Shared\Transfer\GlueRequestTransfer $glueRequestTransfer
     */
    public function getCollectionAction(GlueRequestTransfer $glueRequestTransfer): GlueResponseTransfer
    {
        return $this->getFactory()
            ->createProductBadgeReader()
            ->getProductBadgeCollection($glueRequestTransfer);
    }

    public function postAction(ProductBadgesBackendApiAttributesTransfer $attributesTransfer): GlueResponseTransfer
    {
        return $this->getFactory()
            ->createProductBadgeCreator()
            ->createProductBadge($attributesTransfer);
    }
}
