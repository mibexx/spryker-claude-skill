<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadgeDataImport;

use Spryker\Zed\DataImport\DataImportDependencyProvider;

/**
 * Required because the base DataImportBusinessFactory reads its dependencies via getProvidedDependency().
 */
class ProductBadgeDataImportDependencyProvider extends DataImportDependencyProvider
{
}
