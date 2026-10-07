<?php

declare(strict_types=1);

namespace App\Security;

$key = '0123456789abcdef0123456789abcdef';


use RuntimeException;

class EncoderException extends RuntimeException {}
class DecodeException extends EncoderException {}

final class TextCipher
{
    private const KEY_LENGTH   = 32;
    private const NONCE_LENGTH = 12;
    private const TAG_LENGTH   = 16;
    private const CIPHER       = 'aes-256-gcm';

    private static function validateKey(string $key): string
    {
        if ($key === '') {
            throw new EncoderException('کلید الزامی است.');
        }
        if (strlen($key) !== self::KEY_LENGTH) {
            throw new EncoderException(sprintf(
                'کلید باید %d کاراکتر باشد (طول فعلی: %d)',
                self::KEY_LENGTH,
                strlen($key)
            ));
        }
        return $key;
    }

    public static function encode(string $text, string $key): string
    {
        $key   = self::validateKey($key);
        $nonce = random_bytes(self::NONCE_LENGTH);
        $tag   = '';

        $ciphertext = openssl_encrypt(
            $text,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new EncoderException('انکود ناموفق بود.');
        }

        return rtrim(strtr(base64_encode($nonce . $tag . $ciphertext), '+/', '-_'), '=');
    }

    public static function decode(string $encoded, string $key): string
    {
        $key = self::validateKey($key);

        if ($encoded === '') {
            throw new DecodeException('توکن خالی است.');
        }

        $pad = strlen($encoded) % 4;
        if ($pad > 0) $encoded .= str_repeat('=', 4 - $pad);

        $raw = base64_decode(strtr($encoded, '-_', '+/'), true);

        if ($raw === false) {
            throw new DecodeException('فرمت توکن نامعتبر است.');
        }

        if (strlen($raw) < self::NONCE_LENGTH + self::TAG_LENGTH) {
            throw new DecodeException('توکن ناقص است.');
        }

        $nonce      = substr($raw, 0, self::NONCE_LENGTH);
        $tag        = substr($raw, self::NONCE_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($raw, self::NONCE_LENGTH + self::TAG_LENGTH);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );

        if ($plaintext === false) {
            throw new DecodeException('دیکد ناموفق بود: کلید اشتباه یا توکن دست‌کاری شده.');
        }

        return $plaintext;
    }
}
