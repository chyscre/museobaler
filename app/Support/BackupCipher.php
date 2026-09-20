<?php

namespace App\Support;

use RuntimeException;

/**
 * Encrypting a backup at rest.
 *
 * A dump holds every visitor's name, age, email and home address in one
 * file - it is the most sensitive artefact this system produces, and it is
 * the one most likely to be copied onto a USB stick or a shared drive.
 * Encrypted, a stick that goes missing is a lost stick, not a data breach
 * the Tourism office has to report under the Data Privacy Act.
 *
 * libsodium's secretstream: authenticated (a tampered file fails to decrypt
 * rather than restoring corrupt data) and streamed in chunks, so the file
 * never has to fit in memory. Ships with PHP 8, no extension to install.
 *
 * Format: 24-byte header, then chunks of (1 MiB + 17 bytes of tag).
 *
 * The key is BACKUP_ENCRYPTION_KEY in .env - 32 random bytes, base64.
 * `php artisan db:backup:key` prints a fresh one. Keep a copy of the key
 * somewhere that is not this machine, or the backups are unreadable when
 * this machine is the thing that died.
 */
class BackupCipher
{
    private const CHUNK = 1024 * 1024;

    /** The key from config, or null when encryption is not configured. */
    public static function key(): ?string
    {
        $encoded = (string) config('backup.encryption_key');

        if ($encoded === '') {
            return null;
        }

        $key = base64_decode($encoded, true);

        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new RuntimeException('BACKUP_ENCRYPTION_KEY is not a base64-encoded 32-byte key. Run `php artisan db:backup:key` to make one.');
        }

        return $key;
    }

    public static function generateKey(): string
    {
        return base64_encode(sodium_crypto_secretstream_xchacha20poly1305_keygen());
    }

    /** Encrypt $source into $target. Returns $target. */
    public static function encrypt(string $source, string $target, string $key): string
    {
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);

        $in  = self::open($source, 'rb');
        $out = self::open($target, 'wb');

        fwrite($out, $header);

        while (!feof($in)) {
            $chunk = fread($in, self::CHUNK);
            if ($chunk === '' || $chunk === false) {
                break;
            }
            $tag = feof($in)
                ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
            fwrite($out, sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag));
        }

        fclose($in);
        fclose($out);

        return $target;
    }

    /** Decrypt $source into $target. Throws if the key is wrong or the file was altered. */
    public static function decrypt(string $source, string $target, string $key): string
    {
        $in = self::open($source, 'rb');

        $header = fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
        if ($header === false || strlen($header) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
            fclose($in);
            throw new RuntimeException('Not an encrypted backup: the header is missing.');
        }

        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
        $out   = self::open($target, 'wb');

        while (!feof($in)) {
            $chunk = fread($in, self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES);
            if ($chunk === '' || $chunk === false) {
                break;
            }
            $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $chunk);
            if ($result === false) {
                fclose($in);
                fclose($out);
                @unlink($target);
                throw new RuntimeException('Decryption failed: wrong key, or the file has been altered.');
            }
            [$plain, $tag] = $result;
            fwrite($out, $plain);
            if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                break;
            }
        }

        fclose($in);
        fclose($out);

        return $target;
    }

    /** @return resource */
    private static function open(string $path, string $mode)
    {
        $handle = @fopen($path, $mode);

        if ($handle === false) {
            throw new RuntimeException("Could not open {$path}.");
        }

        return $handle;
    }
}
