<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Yves\ProductBadgeWidget\Widget;

use Spryker\Yves\Kernel\Widget\AbstractWidget;

/**
 * @method \Pyz\Yves\ProductBadgeWidget\ProductBadgeWidgetFactory getFactory()
 */
class ProductBadgeWidget extends AbstractWidget
{
    protected const PARAMETER_PRODUCT_BADGES = 'productBadges';

    public function __construct(int $idProductAbstract)
    {
        $this->addProductBadgesParameter($idProductAbstract);
    }

    public static function getName(): string
    {
        return 'ProductBadgeWidget';
    }

    public static function getTemplate(): string
    {
        return '@ProductBadgeWidget/views/product-badge-widget/product-badge-widget.twig';
    }

    protected function addProductBadgesParameter(int $idProductAbstract): void
    {
        $this->addParameter(
            static::PARAMETER_PRODUCT_BADGES,
            $this->getFactory()->getProductBadgeClient()->findProductBadgesByIdProductAbstract($idProductAbstract),
        );
    }
}
