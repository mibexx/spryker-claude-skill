<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadgeDataImport\Business;

use Generated\Shared\Transfer\DataImporterConfigurationTransfer;
use Generated\Shared\Transfer\DataImporterReportTransfer;
use Spryker\Zed\Kernel\Business\AbstractFacade;

/**
 * @method \Pyz\Zed\ProductBadgeDataImport\Business\ProductBadgeDataImportBusinessFactory getFactory()
 */
class ProductBadgeDataImportFacade extends AbstractFacade implements ProductBadgeDataImportFacadeInterface
{
    /**
     * {@inheritDoc}
     *
     * @api
     */
    public function importProductBadge(?DataImporterConfigurationTransfer $dataImporterConfigurationTransfer = null): DataImporterReportTransfer
    {
        return $this->getFactory()
            ->getProductBadgeDataImporter($dataImporterConfigurationTransfer)
            ->import($dataImporterConfigurationTransfer);
    }
}
