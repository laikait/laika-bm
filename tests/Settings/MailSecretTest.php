<?php
/**
 * Laika Bill Manager
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 * License: Proprietary - see LICENSE
 * This file is part of Laika Bill Manager.
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace LBM\Tests\Settings;

use Laika\Model\Model;
use LBM\Action\Setting;
use LBM\Mail\MailerFactory;
use LBM\Migration\M202609190300SealMailPassword;
use LBM\Tests\TestCase;

/**
 * Phase 51: the SMTP password is stored sealed and exactly as typed, kept by a
 * blank box, never handed to the settings screen, and opened for the mailer.
 */
final class MailSecretTest extends TestCase
{
    /** The request sanitizer's encoding, as the settings form's input arrives. */
    private static function posted(string $typed): string
    {
        return htmlspecialchars($typed, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function testATypedPasswordIsStoredSealedAndExactlyAsTyped(): void
    {
        (new Setting())->saveGroup('mail', ['mail_password' => self::posted('n&w<pw>')]);

        self::assertStringNotContainsString('n&w', $this->stored());
        self::assertSame('n&w<pw>', (new Setting())->secret('mail_password'));
        self::assertSame('n&w<pw>', (new MailerFactory())->config()['password']);
    }

    public function testABlankBoxKeepsTheSavedPassword(): void
    {
        $settings = new Setting();
        $settings->saveGroup('mail', ['mail_password' => self::posted('keep-me')]);

        $settings->saveGroup('mail', ['mail_password' => '']);

        self::assertSame('keep-me', $settings->secret('mail_password'));
    }

    public function testTheScreenNeverReceivesIt(): void
    {
        $settings = new Setting();
        $settings->saveGroup('mail', ['mail_password' => self::posted('secret')]);

        $group = $settings->group('mail');

        self::assertSame('', $group['mail_password']);
        self::assertTrue($group['mail_password_saved']);
    }

    public function testTheMigrationSealsALegacyValueAndDecodesIt(): void
    {
        // Stored the old way: as typed AND html-encoded by the sanitizer.
        (new Setting())->put('mail_password', 'p&amp;ss');
        $migration = new M202609190300SealMailPassword();

        self::assertTrue($migration->applies());
        self::assertSame('p&amp;ss', (new Setting())->secret('mail_password'), 'Still usable before migrating.');

        $migration->run();

        self::assertSame('p&ss', (new Setting())->secret('mail_password'));
        self::assertFalse($migration->applies());
    }

    private function stored(): string
    {
        return (string) ((new Model())->table('options')->where(['op_key' => 'mail_password'])->first()['op_value'] ?? '');
    }
}
