<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadgeGui\Communication\Controller;

use Generated\Shared\Transfer\ProductBadgeTransfer;
use Pyz\Zed\ProductBadge\Business\Exception\InvalidProductBadgeException;
use Pyz\Zed\ProductBadgeGui\ProductBadgeGuiConfig;
use Spryker\Zed\Kernel\Communication\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * @method \Pyz\Zed\ProductBadgeGui\Communication\ProductBadgeGuiCommunicationFactory getFactory()
 */
class CreateController extends AbstractController
{
    protected const MESSAGE_PRODUCT_BADGE_CREATED = 'Product badge "%s" was created successfully.';

    protected const MESSAGE_PARAM_LABEL = '%s';

    /**
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|array<string, mixed>
     */
    public function indexAction(Request $request): RedirectResponse|array
    {
        $dataProvider = $this->getFactory()->createProductBadgeFormDataProvider();
        $productBadgeForm = $this->getFactory()
            ->getProductBadgeForm($dataProvider->getData(), $dataProvider->getOptions())
            ->handleRequest($request);

        if ($productBadgeForm->isSubmitted() && $productBadgeForm->isValid()) {
            /** @var \Generated\Shared\Transfer\ProductBadgeTransfer $productBadgeTransfer */
            $productBadgeTransfer = $productBadgeForm->getData();

            $redirectResponse = $this->createProductBadge($productBadgeTransfer);

            if ($redirectResponse !== null) {
                return $redirectResponse;
            }
        }

        return $this->viewResponse([
            'form' => $productBadgeForm->createView(),
        ]);
    }

    /**
     * Returns null when the facade rejects the badge (unknown product or badge key not allowed);
     * the error message is shown and the form is rendered again instead of ending in an HTTP 500.
     */
    protected function createProductBadge(ProductBadgeTransfer $productBadgeTransfer): ?RedirectResponse
    {
        try {
            $productBadgeTransfer = $this->getFactory()
                ->getProductBadgeFacade()
                ->createProductBadge($productBadgeTransfer);
        } catch (InvalidProductBadgeException $exception) {
            $this->addErrorMessage($exception->getMessage());

            return null;
        }

        $this->addSuccessMessage(
            static::MESSAGE_PRODUCT_BADGE_CREATED,
            [static::MESSAGE_PARAM_LABEL => $productBadgeTransfer->getLabel()],
        );

        return $this->redirectResponse(ProductBadgeGuiConfig::URL_PRODUCT_BADGE_LIST);
    }
}
