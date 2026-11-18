<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Yves\ProductBadgePage\Controller;

use Spryker\Yves\Kernel\Controller\AbstractController;
use Spryker\Yves\Kernel\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @method \Pyz\Yves\ProductBadgePage\ProductBadgePageFactory getFactory()
 */
class ProductBadgeController extends AbstractController
{
    public function indexAction(string $idProductAbstract): View
    {
        $productBadgeTransfers = $this->getFactory()
            ->createProductBadgeReader()
            ->getProductBadgesByIdProductAbstract((int)$idProductAbstract);

        if ($productBadgeTransfers === []) {
            throw new NotFoundHttpException(sprintf('No product badges found for abstract product "%s".', $idProductAbstract));
        }

        return $this->view(
            [
                'idProductAbstract' => (int)$idProductAbstract,
                'productBadges' => $productBadgeTransfers,
            ],
            [],
            '@ProductBadgePage/views/product-badge/product-badge.twig',
        );
    }
}
