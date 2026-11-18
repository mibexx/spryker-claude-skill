<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadgeDataImport\Business;

use Generated\Shared\Transfer\DataImporterConfigurationTransfer;
use Pyz\Zed\ProductBadgeDataImport\Business\Writer\ProductBadge\AbstractSkuToIdProductAbstractStep;
use Pyz\Zed\ProductBadgeDataImport\Business\Writer\ProductBadge\ProductBadgeWriterStep;
use Spryker\Zed\DataImport\Business\DataImportBusinessFactory;
use Spryker\Zed\DataImport\Business\Model\DataImporterInterface;
use Spryker\Zed\DataImport\Business\Model\DataImportStep\DataImportStepInterface;

/**
 * @method \Pyz\Zed\ProductBadgeDataImport\ProductBadgeDataImportConfig getConfig()
 */
class ProductBadgeDataImportBusinessFactory extends DataImportBusinessFactory
{
    public function getProductBadgeDataImporter(?DataImporterConfigurationTransfer $dataImporterConfigurationTransfer = null): DataImporterInterface
    {
        /** @var \Spryker\Zed\DataImport\Business\Model\DataImporter $dataImporter */
        $dataImporter = $this->getCsvDataImporterFromConfig(
            $dataImporterConfigurationTransfer ?: $this->getConfig()->getProductBadgeDataImporterConfiguration(),
        );

        // Step order matters: lookups first, writer last. Each step runs once per CSV row.
        $dataSetStepBroker = $this->createTransactionAwareDataSetStepBroker();
        $dataSetStepBroker->addStep($this->createAbstractSkuToIdProductAbstractStep());
        $dataSetStepBroker->addStep($this->createProductBadgeWriterStep());

        $dataImporter->addDataSetStepBroker($dataSetStepBroker);

        return $dataImporter;
    }

    public function createAbstractSkuToIdProductAbstractStep(): DataImportStepInterface
    {
        return new AbstractSkuToIdProductAbstractStep();
    }

    public function createProductBadgeWriterStep(): DataImportStepInterface
    {
        return new ProductBadgeWriterStep();
    }
}
