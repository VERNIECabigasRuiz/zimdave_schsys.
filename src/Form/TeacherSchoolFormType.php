<?php

namespace App\Form;

use App\Schools\Domain\Model\School;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotNull;

class TeacherSchoolFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('school', EntityType::class, [
            'class' => School::class,
            'choice_label' => static fn (School $school) => $school->getSchoolId().' · '.$school->getName(),
            'query_builder' => static fn (EntityRepository $schools) => $schools->createQueryBuilder('s')
                ->where('s.active = true')
                ->orderBy('s.name'),
            'placeholder' => 'Choose a school',
            'constraints' => [new NotNull(message: 'Choose a school.')],
        ]);
    }
}
