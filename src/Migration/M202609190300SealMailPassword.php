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

namespace LBM\Migration;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Throwable;
use Laika\Service\Vault;
use LBM\Action\Setting;
use LBM\Contract\MigrationAbstract;
use LBM\Support\Plain;

/**
 * The eleventh real migration: Phase 51 seals the SMTP password.
 *
 * Until Phase 51 `options.mail_password` was stored as typed - and as the
 * request sanitizer had HTML-encoded it, so a password with `&` in it was saved
 * as `&amp;` and never worked. Setting now seals it with Vault on save and reads
 * it with Setting::secret(). This seals the one already stored, decoding it
 * first so a password that was broken by the encoding starts working.
 *
 * applies() asks Vault, not a flag: a value Vault can open is sealed already
 * (saved through the new screen, or a fresh install), and there is nothing to do.
 * Until this runs, Setting::secret() hands the old value through unchanged, so
 * mail keeps going on an install that has not updated yet.
 */
class M202609190300SealMailPassword extends MigrationAbstract
{
    /** @var string Ledger Key. Written once, never edited */
    protected string $id = '20260919_0300_seal_mail_password';

    /** @var string What This Does */
    protected string $description
        = 'Encrypt the stored SMTP password, which was kept as typed.';

    /**
     * Whether This Install Needs It
     * @return bool
     */
    public function applies(): bool
    {
        if (!$this->hasTable('options')) {
            return false;
        }

        $stored = (string) (option('mail_password', '') ?? '');

        if ($stored === '') {
            return false;
        }

        try {
            Vault::decrypt($stored);

            return false;
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Seal It
     * @return void
     */
    public function run(): void
    {
        $plain = Plain::text((string) (option('mail_password', '') ?? ''));

        (new Setting())->put('mail_password', Vault::encrypt($plain));
    }
}
