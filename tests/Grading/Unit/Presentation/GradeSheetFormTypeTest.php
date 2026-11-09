<?php

declare(strict_types=1);

namespace App\Tests\Grading\Unit\Presentation;

use App\Grading\Presentation\Form\GradeSheetFormType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\Forms;

final class GradeSheetFormTypeTest extends TestCase
{
    public function testAPostWithoutDataIsLeftAlone(): void
    {
        // Initialize
        $form = Forms::createFormFactory()->createBuilder()->add('grades', CollectionType::class)->getForm();
        $event = new FormEvent($form, null);

        // Execute
        GradeSheetFormType::matchRosterOf($event, 'grades');

        // Assert
        self::assertNull($event->getData());
        self::assertCount(0, $form->getErrors());
    }

    public function testAMissingEntryWithFieldsKeepsWhatTheFormHoldsAndRefusesTheSheet(): void
    {
        // Initialize
        $entry = new /** @extends AbstractType<mixed> */ class extends AbstractType {
            public function buildForm(FormBuilderInterface $builder, array $options): void
            {
                $builder->add('code', TextType::class);
            }
        };
        $factory = Forms::createFormFactoryBuilder()->addType($entry)->getFormFactory();
        $form = $factory->createBuilder(FormType::class, ['entries' => [5 => ['code' => 'x'], 6 => ['code' => 'y']]])
            ->add('entries', CollectionType::class, ['entry_type' => $entry::class])
            ->getForm();
        $event = new FormEvent($form, ['entries' => [5 => ['code' => 'z']]]);

        // Execute
        GradeSheetFormType::matchRosterOf($event, 'entries');

        // Assert
        self::assertSame(['entries' => [5 => ['code' => 'z'], 6 => ['code' => 'y']]], $event->getData());
        self::assertSame(GradeSheetFormType::ROSTER_CHANGED, $form->getErrors()[0]->getMessage());
    }
}
