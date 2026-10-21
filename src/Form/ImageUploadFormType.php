<?php

namespace App\Form;

use App\Service\ImageProcessor;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Component\Validator\Constraints\NotNull;

/** A single photo/logo upload, checked by content type (not the file name). */
class ImageUploadFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('image', FileType::class, [
            'label' => 'New photo',
            'attr' => ['accept' => 'image/jpeg,image/png,image/webp', 'data-action' => 'image-preview#show'],
            'constraints' => [new NotNull(message: 'Choose a photo to upload.'), self::imageConstraint()],
        ]);
    }

    public static function imageConstraint(): Image
    {
        return new Image(
            maxSize: '5M',
            mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
            mimeTypesMessage: 'Upload a JPG, PNG or WebP image.',
            maxPixels: ImageProcessor::MAX_PIXELS,
            maxPixelsMessage: 'The image is too large. Use one under 25 megapixels.',
        );
    }
}
