<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace PyzTest\Glue\ProductBadges;

use Pyz\Glue\ProductBadgesRestApi\ProductBadgesRestApiConfig;
use SprykerTest\Glue\Testify\Tester\ApiEndToEndTester;

/**
 * Inherited Methods
 *
 * @method void wantToTest($text)
 * @method void wantTo($text)
 * @method void execute($callable)
 * @method void expectTo($prediction)
 * @method void expect($prediction)
 * @method void amGoingTo($argumentation)
 * @method void am($role)
 * @method void lookForwardTo($achieveValue)
 * @method void comment($description)
 * @method void pause()
 *
 * @SuppressWarnings(\PyzTest\Glue\ProductBadges\PHPMD)
 */
class ProductBadgesApiTester extends ApiEndToEndTester
{
    use _generated\ProductBadgesApiTesterActions;

    public function buildProductBadgesUrl(?int $idProductAbstract = null, ?string $badgeKey = null): string
    {
        $url = '{resourceProductBadges}';
        $params = ['resourceProductBadges' => ProductBadgesRestApiConfig::RESOURCE_PRODUCT_BADGES];

        if ($badgeKey !== null) {
            $url .= '/{badgeKey}';
            $params['badgeKey'] = $badgeKey;
        }

        if ($idProductAbstract !== null) {
            $url .= '?' . ProductBadgesRestApiConfig::PARAMETER_ID_PRODUCT_ABSTRACT . '=' . $idProductAbstract;
        }

        return $this->formatFullUrl($url, $params);
    }
}
