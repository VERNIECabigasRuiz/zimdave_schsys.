<?php

namespace App\Tests\Access\Functional;

use App\Access\Domain\Model\User;
use App\Tests\Support\AdminTestCase;
use App\Tests\Core\Integration\Infrastructure\FileStorage\ImageProcessorTest;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Field\FileFormField;

class ProfilePhotoControllerTest extends AdminTestCase
{
    use ClockSensitiveTrait;

    private User $maria;

    protected function setUp(): void
    {
        parent::setUp();
        static::mockTime('2026-10-16');
        $this->maria = $this->createUser('maria', User::ROLE_PRINCIPAL, 'Maria Santos');
        $this->client->loginUser($this->maria);
    }

    private function uploadPhoto(string $path, string $name = 'photo.jpg'): void
    {
        $crawler = $this->client->request('GET', '/profile');
        $form = $crawler->selectButton('Upload photo')->form();
        $field = $form['image_upload_form[image]'];
        self::assertInstanceOf(FileFormField::class, $field);
        $field->upload($path);
        $files = $form->getPhpFiles();
        $files['image_upload_form']['image']['name'] = $name;

        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), $files);
    }

    private function jpegFile(): string
    {
        $img = imagecreatetruecolor(800, 800);
        $path = sys_get_temp_dir() . '/zim_photo_' . bin2hex(random_bytes(4)) . '.jpg';
        imagejpeg($img, $path);

        return $path;
    }

    /** Maria's stored photo; the test fails when she has none. */
    private function storedPhoto(): string
    {
        $photo = $this->findUser('maria')->getPhotoPath();
        self::assertNotNull($photo);

        return $photo;
    }

    private function absolute(string $path): string
    {
        return static::getContainer()->getParameter('app.upload_root') . '/' . $path;
    }

    public function testPhotoInputShowsPreviewBeforeUploading(): void
    {
        $crawler = $this->client->request('GET', '/profile');

        $input = '[data-controller~="image-preview"] input#image_upload_form_image[data-action~="image-preview#show"]';
        self::assertCount(1, $crawler->filter($input));
        self::assertCount(1, $crawler->filter('[data-image-preview-target="image"]'));
        $hint = $crawler->filter('[data-image-preview-target="hint"]')->text();
        self::assertStringContainsString('Click "Upload photo"', $hint);
    }

    public function testUploadPhotoStores350x450(): void
    {
        $this->uploadPhoto($this->jpegFile());

        self::assertResponseRedirects('/profile');
        $photo = $this->storedPhoto();
        self::assertMatchesRegularExpression('#^uploads/photos/[0-9a-f]{32}\.webp$#', $photo);
        $size = getimagesize($this->absolute($photo));
        self::assertIsArray($size);
        self::assertSame([350, 450], \array_slice($size, 0, 2));
        self::assertContains('Changed profile photo', $this->activityActions());
        unlink($this->absolute($photo));
    }

    public function testFakeJpgRejectedAndOldPhotoKept(): void
    {
        $this->uploadPhoto($this->jpegFile());
        $old = $this->storedPhoto();
        $fake = sys_get_temp_dir() . '/zim_fake_' . bin2hex(random_bytes(4)) . '.jpg';
        file_put_contents($fake, '%PDF-1.4 not an image');

        $this->uploadPhoto($fake);

        self::assertResponseRedirects('/profile');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Upload a JPG, PNG or WebP image.');
        self::assertSame($old, $this->findUser('maria')->getPhotoPath());
        self::assertFileExists($this->absolute($old));
        unlink($this->absolute($old));
    }

    public function testHugeImageShowsErrorInsteadOfCrashing(): void
    {
        $this->uploadPhoto(ImageProcessorTest::hugePngHeader(sys_get_temp_dir()), 'bomb.png');

        self::assertResponseRedirects('/profile');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'The image is too large.');
        self::assertNull($this->findUser('maria')->getPhotoPath());
    }

    public function testRemovePhotoRequiresCsrfAndDeletesFile(): void
    {
        $this->uploadPhoto($this->jpegFile());
        $photo = $this->storedPhoto();

        $this->client->request('POST', '/profile/photo/remove', ['_token' => 'wrong']);
        self::assertSame($photo, $this->findUser('maria')->getPhotoPath());

        $crawler = $this->client->request('GET', '/profile');
        $this->client->submit($crawler->selectButton('Remove photo')->form());

        self::assertResponseRedirects('/profile');
        self::assertNull($this->findUser('maria')->getPhotoPath());
        self::assertFileDoesNotExist($this->absolute($photo));
        self::assertContains('Removed profile photo', $this->activityActions());
    }

    public function testUnreadableImageThatPassesTheFormShowsTheError(): void
    {
        // Initialize
        $corrupt = sys_get_temp_dir() . '/zim_corrupt_' . bin2hex(random_bytes(4)) . '.png';
        ob_start();
        imagepng(imagecreatetruecolor(40, 40));
        $png = (string) ob_get_clean();
        // Only the signature and header (what the form checks) are kept; with no image data GD can't decode it
        file_put_contents($corrupt, substr($png, 0, 33));

        // Execute
        $this->uploadPhoto($corrupt, 'photo.png');

        // Assert
        self::assertResponseRedirects('/profile');
        $this->client->followRedirect();
        self::assertSelectorExists('.alert--error');
        self::assertNull($this->findUser('maria')->getPhotoPath());
    }
}
