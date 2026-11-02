<?php

namespace App\LearnerId;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode as QrEncoder;
use chillerlan\QRCode\QROptions;

/** QR codes for ID cards, as inline PNGs (Dompdf may not load files or URLs). */
class QrCode
{
    /** "data:image/png;base64,…" */
    public function png(string $text): string
    {
        $options = new QROptions([
            'outputInterface' => QRGdImagePNG::class,
            'eccLevel' => EccLevel::M,
            'scale' => 8,
            'quietzoneSize' => 2,
            'outputBase64' => true,
        ]);

        return (new QrEncoder($options))->render($text);
    }
}
