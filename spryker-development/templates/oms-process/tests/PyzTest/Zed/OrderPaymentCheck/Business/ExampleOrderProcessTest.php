<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace PyzTest\Zed\OrderPaymentCheck\Business;

use Codeception\Test\Unit;
use DateInterval;
use Orm\Zed\Payment\Persistence\SpySalesPayment;
use Orm\Zed\Payment\Persistence\SpySalesPaymentMethodTypeQuery;
use Orm\Zed\Sales\Persistence\SpySalesOrderItemQuery;
use Pyz\Zed\OrderPaymentCheck\Communication\Plugin\Oms\Command\LogOrderConfirmedCommandPlugin;
use Pyz\Zed\OrderPaymentCheck\Communication\Plugin\Oms\Condition\HasPaymentConditionPlugin;
use PyzTest\Zed\OrderPaymentCheck\OrderPaymentCheckBusinessTester;
use Spryker\Shared\Oms\OmsConstants;
use Spryker\Zed\Oms\Business\OmsFacade;

/**
 * Auto-generated group annotations
 *
 * @group PyzTest
 * @group Zed
 * @group OrderPaymentCheck
 * @group Business
 * @group ExampleOrderProcessTest
 * Add your own group annotations below this line
 */
class ExampleOrderProcessTest extends Unit
{
    protected OrderPaymentCheckBusinessTester $tester;

    public function testExampleOrder01RunsCommandConditionAndTimeout(): void
    {
        // Arrange
        $this->tester->setConfig(OmsConstants::ACTIVE_PROCESSES, ['ExampleOrder01']);
        $this->tester->addCommand('ExampleOrder/LogOrderConfirmed', LogOrderConfirmedCommandPlugin::class);
        $this->tester->addCondition('ExampleOrder/HasPayment', HasPaymentConditionPlugin::class);
        $saveOrderTransfer = $this->tester->haveOrder(['unitPrice' => 100], 'ExampleOrder01');
        $idSalesOrder = $saveOrderTransfer->getIdSalesOrderOrFail();
        $ids = [];
        foreach ($saveOrderTransfer->getOrderItems() as $itemTransfer) {
            $ids[] = $itemTransfer->getIdSalesOrderItemOrFail();
        }

        // Act 1: init event "confirm" (onEnter) runs the command, item -> confirmed
        $this->tester->triggerEventForNewOrderItems($ids);
        $state1 = $this->getStateName($ids[0]);

        // Act 2: condition without payment row -> stays confirmed
        $this->tester->checkCondition();
        $state2 = $this->getStateName($ids[0]);

        // Act 3: payment row -> condition true -> paid
        $typeEntity = SpySalesPaymentMethodTypeQuery::create()->filterByPaymentMethod('x')->filterByPaymentProvider('y')->findOneOrCreate();
        $typeEntity->save();
        $paymentEntity = (new SpySalesPayment())->setFkSalesOrder($idSalesOrder)->setFkSalesPaymentMethodType($typeEntity->getIdSalesPaymentMethodType())->setAmount(100);
        $paymentEntity->save();
        $this->tester->checkCondition();
        $state3 = $this->getStateName($ids[0]);

        // Act 4: manual events
        $omsFacade = new OmsFacade();
        $omsFacade->triggerEventForOrderItems('ship', $ids);
        $state4 = $this->getStateName($ids[0]);
        $omsFacade->triggerEventForOrderItems('close', $ids);
        $state5 = $this->getStateName($ids[0]);

        $paymentEntity->delete();
        $typeEntity->delete();

        // Assert
        $this->assertSame(['confirmed', 'confirmed', 'paid', 'shipped', 'closed'], [$state1, $state2, $state3, $state4, $state5]);
    }

    public function testExampleOrder01TimeoutCancelsUnpaidOrder(): void
    {
        $this->tester->setConfig(OmsConstants::ACTIVE_PROCESSES, ['ExampleOrder01']);
        $this->tester->addCommand('ExampleOrder/LogOrderConfirmed', LogOrderConfirmedCommandPlugin::class);
        $this->tester->addCondition('ExampleOrder/HasPayment', HasPaymentConditionPlugin::class);
        $saveOrderTransfer = $this->tester->haveOrder(['unitPrice' => 100], 'ExampleOrder01');
        $ids = [];
        foreach ($saveOrderTransfer->getOrderItems() as $itemTransfer) {
            $ids[] = $itemTransfer->getIdSalesOrderItemOrFail();
        }
        $this->tester->triggerEventForNewOrderItems($ids);
        $this->tester->moveItemAfterTimeOut($ids[0], new DateInterval('P15D'));
        $this->tester->checkTimeout();

        $this->assertSame('cancelled', $this->getStateName($ids[0]));
    }

    protected function getStateName(int $idSalesOrderItem): string
    {
        $entity = SpySalesOrderItemQuery::create()->findOneByIdSalesOrderItem($idSalesOrderItem);
        $entity->reload(true);

        return $entity->getState()->getName();
    }
}
