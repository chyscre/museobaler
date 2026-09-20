<?php

namespace Tests\Feature;

use App\Support\BackupCipher;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Encrypted backups round-trip, refuse the wrong key, and refuse a file
 * somebody has edited.
 */
class BackupCipherTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/museobaler-cipher-' . uniqid();
        File::makeDirectory($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_a_dump_survives_encrypt_then_decrypt_byte_for_byte(): void
    {
        // Larger than one chunk, so the multi-chunk path is what runs.
        $plain = random_bytes(3 * 1024 * 1024 + 123);
        file_put_contents("{$this->dir}/dump.sql.gz", $plain);

        $key = base64_decode(BackupCipher::generateKey());

        BackupCipher::encrypt("{$this->dir}/dump.sql.gz", "{$this->dir}/dump.sql.gz.enc", $key);
        $this->assertNotSame($plain, file_get_contents("{$this->dir}/dump.sql.gz.enc"));

        BackupCipher::decrypt("{$this->dir}/dump.sql.gz.enc", "{$this->dir}/restored.sql.gz", $key);
        $this->assertSame($plain, file_get_contents("{$this->dir}/restored.sql.gz"));
    }

    public function test_the_wrong_key_is_refused(): void
    {
        file_put_contents("{$this->dir}/dump.sql.gz", 'visitors');
        BackupCipher::encrypt("{$this->dir}/dump.sql.gz", "{$this->dir}/dump.enc", base64_decode(BackupCipher::generateKey()));

        $this->expectExceptionMessage('wrong key');
        BackupCipher::decrypt("{$this->dir}/dump.enc", "{$this->dir}/out", base64_decode(BackupCipher::generateKey()));
    }

    public function test_a_tampered_file_is_refused_rather_than_restored_corrupt(): void
    {
        $key = base64_decode(BackupCipher::generateKey());
        file_put_contents("{$this->dir}/dump.sql.gz", str_repeat('visitor rows ', 1000));
        BackupCipher::encrypt("{$this->dir}/dump.sql.gz", "{$this->dir}/dump.enc", $key);

        $bytes = file_get_contents("{$this->dir}/dump.enc");
        $bytes[100] = chr(ord($bytes[100]) ^ 0xFF);
        file_put_contents("{$this->dir}/dump.enc", $bytes);

        $this->expectExceptionMessage('altered');
        BackupCipher::decrypt("{$this->dir}/dump.enc", "{$this->dir}/out", $key);
    }

    public function test_the_decrypt_command_opens_a_dump_with_the_env_key(): void
    {
        $encoded = BackupCipher::generateKey();
        config(['backup.encryption_key' => $encoded]);

        file_put_contents("{$this->dir}/museobaler.sql.gz", 'CREATE TABLE visitors');
        BackupCipher::encrypt("{$this->dir}/museobaler.sql.gz", "{$this->dir}/museobaler.sql.gz.enc", base64_decode($encoded));
        unlink("{$this->dir}/museobaler.sql.gz");

        $this->artisan('db:backup:decrypt', ['file' => "{$this->dir}/museobaler.sql.gz.enc"])
            ->assertSuccessful();

        $this->assertSame('CREATE TABLE visitors', file_get_contents("{$this->dir}/museobaler.sql.gz"));
    }

    public function test_a_malformed_key_is_reported_not_used(): void
    {
        config(['backup.encryption_key' => 'not-a-key']);

        $this->expectExceptionMessage('db:backup:key');
        BackupCipher::key();
    }

    public function test_no_key_means_no_encryption(): void
    {
        config(['backup.encryption_key' => null]);

        $this->assertNull(BackupCipher::key());
    }
}
