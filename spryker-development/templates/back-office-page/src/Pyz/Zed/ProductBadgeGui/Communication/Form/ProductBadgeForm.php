<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadgeGui\Communication\Form;

use Generated\Shared\Transfer\ProductBadgeTransfer;
use Spryker\Zed\Kernel\Communication\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThan;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @method \Pyz\Zed\ProductBadgeGui\Communication\ProductBadgeGuiCommunicationFactory getFactory()
 * @method \Pyz\Zed\ProductBadgeGui\ProductBadgeGuiConfig getConfig()
 */
class ProductBadgeForm extends AbstractType
{
    protected const FIELD_FK_PRODUCT_ABSTRACT = 'fkProductAbstract';

    protected const FIELD_BADGE_KEY = 'badgeKey';

    protected const FIELD_LABEL = 'label';

    public function getBlockPrefix(): string
    {
        return 'product_badge';
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);

        $resolver->setDefaults([
            'data_class' => ProductBadgeTransfer::class,
        ]);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter
    {
        $this->addFkProductAbstractField($builder)
            ->addBadgeKeyField($builder)
            ->addLabelField($builder);
    }

    protected function addFkProductAbstractField(FormBuilderInterface $builder): static
    {
        $builder->add(static::FIELD_FK_PRODUCT_ABSTRACT, IntegerType::class, [
            'label' => 'Product Abstract ID',
            'required' => true,
            'constraints' => [
                new NotBlank(),
                new GreaterThan(0),
            ],
        ]);

        return $this;
    }

    protected function addBadgeKeyField(FormBuilderInterface $builder): static
    {
        $builder->add(static::FIELD_BADGE_KEY, TextType::class, [
            'label' => 'Badge Key',
            'required' => true,
            'constraints' => [
                new NotBlank(),
                new Length(max: 255),
            ],
        ]);

        return $this;
    }

    protected function addLabelField(FormBuilderInterface $builder): static
    {
        $builder->add(static::FIELD_LABEL, TextType::class, [
            'label' => 'Label',
            'required' => true,
            'constraints' => [
                new NotBlank(),
                new Length(max: 255),
            ],
        ]);

        return $this;
    }
}
