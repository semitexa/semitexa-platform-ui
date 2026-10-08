<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Tests\Unit\Upload;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\UploadedFile;
use Semitexa\PlatformUi\Application\Service\Upload\PlatformUiHugUploadReceiver;
use Semitexa\PlatformUi\Application\Service\Upload\UiTempUploads;
use Semitexa\PlatformUi\Application\Service\Upload\UiUploadTickets;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitActionContext;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitFieldDefinition;
use Semitexa\PlatformUi\Domain\Model\Event\UiFormSubmitResult;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContext;

/**
 * tk-la-uploads: the server decides what a file is (its bytes), what it is
 * called (an id it chose) and how long it lives; the form gets a one-time
 * signed ticket, never the file.
 */
final class UiUploadTest extends TestCase
{
    /** A real 1×1 PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private string $dir;
    private string|false $previousSecret = false;

    protected function setUp(): void
    {
        $this->previousSecret = getenv('APP_SECRET');
        putenv('APP_SECRET=platform-ui-upload-test');
        $this->dir = sys_get_temp_dir() . '/ui-upload-test-' . bin2hex(random_bytes(4));
        UiTempUploads::useDirectory($this->dir);
    }

    protected function tearDown(): void
    {
        UiTempUploads::useDirectory(null);
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        putenv($this->previousSecret === false ? 'APP_SECRET' : 'APP_SECRET=' . $this->previousSecret);
    }

    #[Test]
    public function a_sidecar_still_being_written_is_not_swept_with_its_file(): void
    {
        mkdir($this->dir, 0775, true);
        $id = 'upl_' . str_repeat('a', 32);
        file_put_contents($this->dir . '/' . $id . '.bin', 'bytes');
        file_put_contents($this->dir . '/' . $id . '.json', '');
        $staleId = 'upl_' . str_repeat('b', 32);
        file_put_contents($this->dir . '/' . $staleId . '.bin', 'bytes');
        file_put_contents($this->dir . '/' . $staleId . '.json', '');
        touch($this->dir . '/' . $staleId . '.json', time() - UiTempUploads::TTL_SECONDS - 60);

        UiTempUploads::put($this->file('x', 'next.txt')->tmpPath, ['size' => 1, 'mime' => 'text/plain', 'name' => 'next.txt', 'field' => 'f']);

        self::assertFileExists($this->dir . '/' . $id . '.bin', 'a fresh sidecar that does not decode yet is left alone');
        self::assertFileDoesNotExist($this->dir . '/' . $staleId . '.bin', 'an unreadable sidecar older than the TTL is swept');
        self::assertFileDoesNotExist($this->dir . '/' . $staleId . '.json');
    }

    #[Test]
    public function a_failed_metadata_write_leaves_no_file_behind(): void
    {
        $source = $this->file('x', 'a.txt')->tmpPath;
        try {
            UiTempUploads::put($source, ['size' => 1, 'mime' => 'text/plain', 'name' => "\xB1\x31", 'field' => 'f']);
            self::fail('metadata that cannot be encoded must not be stored');
        } catch (\JsonException) {
        }

        self::assertSame([], glob($this->dir . '/upl_*') ?: [], 'no orphan file the sweep could never see');
        self::assertFileExists($source, 'the source stays where it was');
        @unlink($source);
    }

    #[Test]
    public function an_image_becomes_a_ticket_the_form_redeems_once(): void
    {
        [$status, $body] = (new PlatformUiHugUploadReceiver())->receive($this->claims(['image/*']), $this->file(base64_decode(self::PNG), 'me.png'));

        self::assertSame(200, $status);
        self::assertSame('image/png', $body['type']);
        self::assertSame('me.png', $body['name']);
        self::assertCount(2, glob($this->dir . '/upl_*') ?: [], 'stored as <server id>.bin + its metadata, nothing named by the client');

        $file = UiUploadTickets::fromForm($this->context(['avatar' => $body['ticket']]), 'avatar');
        self::assertNotNull($file);
        self::assertSame('png', $file->extension());
        self::assertSame(base64_decode(self::PNG), $file->contents());
        self::assertNull(UiUploadTickets::fromForm($this->context(['avatar' => $body['ticket']]), 'avatar'), 'one-time');

        $moved = $file->moveTo($this->dir . '/kept');
        self::assertMatchesRegularExpression('#/kept/upl_[a-f0-9]{32}\.png$#', $moved);
        @unlink($moved);
        @rmdir($this->dir . '/kept');
    }

    #[Test]
    public function the_bytes_decide_the_type_not_the_name_or_the_browser(): void
    {
        [$status, $body] = (new PlatformUiHugUploadReceiver())->receive($this->claims(['image/*']), $this->file('<?php echo 1;', 'innocent.png', 'image/png'));

        self::assertSame(422, $status);
        self::assertSame('upload_type_refused', $body['reason']);
        self::assertSame([], glob($this->dir . '/upl_*') ?: [], 'a refused file is never stored');
    }

    #[Test]
    public function a_wildcard_does_not_admit_a_type_the_browser_runs_as_a_document(): void
    {
        // An SVG is image/svg+xml to finfo, and carries script: opened from
        // this origin it is stored XSS. `image/*` means a picture.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
        [$status, $body] = (new PlatformUiHugUploadReceiver())->receive($this->claims(['image/*']), $this->file($svg, 'logo.svg', 'image/svg+xml'));
        self::assertSame(422, $status);
        self::assertSame('upload_type_refused', $body['reason']);

        [$status] = (new PlatformUiHugUploadReceiver())->receive($this->claims(['text/*']), $this->file('<html><body>x</body></html>', 'a.html'));
        self::assertSame(422, $status, 'text/* does not admit HTML');

        [$status] = (new PlatformUiHugUploadReceiver())->receive($this->claims(['image/svg+xml']), $this->file($svg, 'logo.svg'));
        self::assertSame(200, $status, 'a field that lists the type exactly still takes it');
        self::assertTrue(UiUploadTickets::typeAllowed('image/png', ['image/*']));
    }

    #[Test]
    public function the_signed_size_limit_holds(): void
    {
        [$status, $body] = (new PlatformUiHugUploadReceiver())->receive($this->claims(['text/plain'], 10), $this->file(str_repeat('a', 11), 'a.txt'));

        self::assertSame(422, $status);
        self::assertSame('upload_too_large', $body['reason']);
    }

    #[Test]
    public function a_ticket_is_only_for_its_own_field_and_a_signed_field(): void
    {
        [, $body] = (new PlatformUiHugUploadReceiver())->receive($this->claims(['image/*']), $this->file(base64_decode(self::PNG), 'me.png'));

        self::assertNull(UiUploadTickets::fromForm($this->context(['cover' => $body['ticket']], ['cover']), 'cover'), 'issued for avatar');
        self::assertNull(UiUploadTickets::fromForm($this->context(['avatar' => $body['ticket']], []), 'avatar'), 'the form did not sign that field');
        self::assertNull(UiUploadTickets::redeem('upl_' . str_repeat('a', 32), 'avatar'), 'not a ticket');
        self::assertNull(UiTempUploads::take('../../etc/passwd'));
    }

    #[Test]
    public function a_context_must_name_what_it_accepts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        UiUploadTickets::context('platform.field', 'uci_upload_test_0001', 'avatar', ['image/*', 'javascript:alert(1)']);
    }

    #[Test]
    public function the_ceiling_follows_the_servers_request_limit(): void
    {
        $previous = getenv('SWOOLE_PACKAGE_MAX_LENGTH');
        try {
            putenv('SWOOLE_PACKAGE_MAX_LENGTH');
            self::assertSame(33_554_432 - UiUploadTickets::REQUEST_OVERHEAD_BYTES, UiUploadTickets::ceilingBytes());

            putenv('SWOOLE_PACKAGE_MAX_LENGTH=52428800');
            self::assertSame(52_428_800 - UiUploadTickets::REQUEST_OVERHEAD_BYTES, UiUploadTickets::ceilingBytes());
            // a field may now take 20 MB, and the limit is signed as asked
            self::assertSame(20_971_520, $this->claims(['application/pdf'], 20_971_520)['mx']);
        } finally {
            putenv($previous === false ? 'SWOOLE_PACKAGE_MAX_LENGTH' : 'SWOOLE_PACKAGE_MAX_LENGTH=' . $previous);
        }
    }

    #[Test]
    public function a_field_promising_more_than_the_server_takes_is_refused_not_clamped(): void
    {
        $previous = getenv('SWOOLE_PACKAGE_MAX_LENGTH');
        try {
            putenv('SWOOLE_PACKAGE_MAX_LENGTH');
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Raise SWOOLE_PACKAGE_MAX_LENGTH');
            UiUploadTickets::context('platform.field', 'uci_upload_test_0001', 'report', ['application/pdf'], 52_428_800); // 50 MB, over the 32 MiB default
        } finally {
            putenv($previous === false ? 'SWOOLE_PACKAGE_MAX_LENGTH' : 'SWOOLE_PACKAGE_MAX_LENGTH=' . $previous);
        }
    }

    /** @return array<string, mixed> */
    private function claims(array $types, int $max = 1048576): array
    {
        return SignedContext::verify(UiUploadTickets::context('platform.field', 'uci_upload_test_0001', 'avatar', $types, $max)) ?? [];
    }

    private function file(string $contents, string $name, string $clientType = 'application/octet-stream'): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($tmp, $contents);

        return new UploadedFile('file', $name, $clientType, strlen($contents), $tmp);
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string>|null    $signedFields
     */
    private function context(array $values, ?array $signedFields = null): UiFormSubmitActionContext
    {
        $fields = array_map(static fn (string $n) => new UiFormSubmitFieldDefinition(name: $n, rules: []), $signedFields ?? array_keys($values));

        return new UiFormSubmitActionContext('uci_upload_form_0001', 'test.upload', 'ui_evt_x', $values, $fields, UiFormSubmitResult::fromFieldResults([]));
    }

    /**
     * The limits were checked against the field the file was uploaded
     * through; a ticket must not be redeemable by another form's field that
     * merely shares the name.
     */
    #[Test]
    public function a_ticket_is_redeemed_only_by_the_field_instance_it_was_uploaded_through(): void
    {
        [, $body] = (new PlatformUiHugUploadReceiver())->receive($this->claims(['image/*']), $this->file(base64_decode(self::PNG), 'me.png'));
        $other = new UiFormSubmitFieldDefinition(name: 'avatar', rules: [], instanceId: 'uci_upload_test_0002');
        $same = new UiFormSubmitFieldDefinition(name: 'avatar', rules: [], instanceId: 'uci_upload_test_0001');
        $contextFor = fn (UiFormSubmitFieldDefinition $field) => new UiFormSubmitActionContext('uci_upload_form_0001', 'test.upload', 'ui_evt_x', ['avatar' => $body['ticket']], [$field], UiFormSubmitResult::fromFieldResults([]));

        self::assertNull(UiUploadTickets::fromForm($contextFor($other), 'avatar'), 'another field instance');
        self::assertNotNull(UiUploadTickets::fromForm($contextFor($same), 'avatar'), 'the field it was uploaded through');
    }
}

