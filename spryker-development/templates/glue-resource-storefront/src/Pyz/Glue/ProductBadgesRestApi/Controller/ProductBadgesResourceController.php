<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Glue\ProductBadgesRestApi\Controller;

use Spryker\Glue\GlueApplication\Rest\JsonApi\RestResponseInterface;
use Spryker\Glue\GlueApplication\Rest\Request\Data\RestRequestInterface;
use Spryker\Glue\Kernel\Controller\AbstractController;

/**
 * @method \Pyz\Glue\ProductBadgesRestApi\ProductBadgesRestApiFactory getFactory()
 */
class ProductBadgesResourceController extends AbstractController
{
    /**
     * @Glue({
     *     "getCollection": {
     *          "summary": [
     *              "Retrieves product badges of an abstract product."
     *          ],
     *          "parameters": [
     *              {"ref": "acceptLanguage"},
     *              {"name": "idProductAbstract", "in": "query", "required": true, "description": "ID of the abstract product."}
     *          ],
     *          "responses": {
     *              "400": "Bad request.",
     *              "404": "Product badge not found."
     *          }
     *     },
     *     "getResourceById": {
     *          "summary": [
     *              "Retrieves a product badge by its badge key."
     *          ],
     *          "parameters": [
     *              {"ref": "acceptLanguage"},
     *              {"name": "idProductAbstract", "in": "query", "required": true, "description": "ID of the abstract product."}
     *          ],
     *          "responses": {
     *              "400": "Bad request.",
     *              "404": "Product badge not found."
     *          }
     *     }
     * })
     *
     * @param \Spryker\Glue\GlueApplication\Rest\Request\Data\RestRequestInterface $restRequest
     */
    public function getAction(RestRequestInterface $restRequest): RestResponseInterface
    {
        return $this->getFactory()
            ->createProductBadgeReader()
            ->getProductBadges($restRequest);
    }
}
