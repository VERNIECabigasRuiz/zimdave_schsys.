<?php

namespace App\Service;

/**
 * Makes easy-to-read temporary passwords such as "kmtp-4821" that an admin can
 * hand to a new user. Look-alike characters (l, o, 0, 1) are left out.
 */
class TemporaryPasswordGenerator
{
    private const LETTERS = 'abcdefghjkmnpqrstuvwxyz';
    private const DIGITS = '23456789';

    public function generate(): string
    {
        return $this->pick(self::LETTERS, 4).'-'.$this->pick(self::DIGITS, 4);
    }

    private function pick(string $alphabet, int $length): string
    {
        $out = '';
        for ($i = 0; $i < $length; ++$i) {
            $out .= $alphabet[random_int(0, \strlen($alphabet) - 1)];
        }

        return $out;
    }
}
