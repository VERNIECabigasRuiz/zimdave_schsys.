<?php

declare(strict_types=1);

namespace App\Announcements\Presentation\Form;

use App\Announcements\Application\UseCase\Command\PostAnnouncementCommand;
use App\Announcements\Application\UseCase\Command\UpdateAnnouncementCommand;
use App\Announcements\Domain\Model\Announcement;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Posts an announcement (bound to PostAnnouncementCommand) or edits one (bound to UpdateAnnouncementCommand).
 *
 * @extends AbstractType<PostAnnouncementCommand|UpdateAnnouncementCommand>
 */
class AnnouncementFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, ['label' => 'Title', 'empty_data' => ''])
            ->add('audience', ChoiceType::class, [
                'label' => 'Who should see it?',
                'choices' => array_flip(Announcement::AUDIENCES),
            ])
            ->add('body', TextareaType::class, [
                'label' => 'Message',
                'empty_data' => '',
                'attr' => ['rows' => 8],
            ]);
    }
}
