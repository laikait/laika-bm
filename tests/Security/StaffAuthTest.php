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

namespace LBM\Tests\Security;

use Laika\Service\Uid;
use LBM\Action\AuthStaff;
use LBM\Action\PasswordReset;
use LBM\Model\EmailQueueModel;
use LBM\Model\StaffModel;
use LBM\Support\LoginThrottle;
use LBM\Support\PasswordValidator;
use LBM\Service\Status;
use LBM\Tests\TestCase;

/**
 * Phase 52: staff sign-in is throttled, staff can reset their own password,
 * and a reset link only ever opens the form for its own kind of account.
 */
final class StaffAuthTest extends TestCase
{
    private const LOGIN = 'throttle-test-staff';

    private int $staffId;
    private string $email;

    protected function setUp(): void
    {
        parent::setUp();

        $this->email = 'staff-' . bin2hex(random_bytes(6)) . '@example.test';
        $model = new StaffModel();

        $model->insert([
            $model->uid       =>  Uid::make(),
            'role_relid'      =>  1,
            'first_name'      =>  'Test',
            'last_name'       =>  'Staff',
            'username'        =>  self::LOGIN,
            'email'           =>  $this->email,
            'status_relid'    =>  Status::idOf('staff_statuses', 'active') ?? 1,
            'staff_created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->staffId = (int) $model->where(['email' => $this->email])->first()[$model->id];
        (new PasswordValidator())->put($this->staffId, PasswordValidator::STAFF, 'Right-Password-123');
    }

    protected function tearDown(): void
    {
        // These tests use the app's own counter directory, as the action does,
        // so they clear exactly the keys they touched. Visitor::ip() reports
        // 0.0.0.0 under the CLI.
        $throttle = new LoginThrottle();
        $throttle->succeeded(ADMIN, self::LOGIN);
        $throttle->reset('login:' . ADMIN . ':ip:0.0.0.0');
        $throttle->reset('reset:' . ADMIN . ':' . $this->email);
        $throttle->reset('reset:' . ADMIN . ':' . self::LOGIN);
        $throttle->reset('reset:' . ADMIN . ':ip:0.0.0.0');

        parent::tearDown();
    }

    public function testWrongPasswordsLockTheAccountWith429Details(): void
    {
        $auth = new AuthStaff();

        for ($i = 0; $i < LoginThrottle::DEFAULTS['max.attempts']; $i++) {
            $result = $auth->attempt(self::LOGIN, 'wrong');
            self::assertSame(AuthStaff::FAILURE, $result['error']);
            self::assertArrayNotHasKey('retry_after', $result);
        }

        // Locked: refused before the password is looked at, even the right one.
        $result = $auth->attempt(self::LOGIN, 'Right-Password-123');

        self::assertFalse($result['ok']);
        self::assertGreaterThan(0, $result['retry_after'] ?? 0);
        self::assertStringContainsString('Too many failed attempts', (string) $result['error']);
    }

    public function testAStaffLinkDoesNotOpenTheClientForm(): void
    {
        $token = (new PasswordReset())->issue($this->staffId, PasswordValidator::STAFF);

        self::assertNull((new \LBM\Action\AuthClient())->findReset($token));
        self::assertNotNull((new AuthStaff())->findReset($token));
    }

    public function testResetChangesThePasswordAndSpendsTheLink(): void
    {
        $token = (new PasswordReset())->issue($this->staffId, PasswordValidator::STAFF);

        $result = (new AuthStaff())->reset($token, 'Brand-New-Pass-456', 'Brand-New-Pass-456');

        self::assertTrue($result['ok'], implode(' ', $result['errors']));

        $passwords = new PasswordValidator();
        self::assertTrue($passwords->verify(
            'Brand-New-Pass-456',
            $passwords->current($this->staffId, PasswordValidator::STAFF)
        ));
        self::assertNull((new AuthStaff())->findReset($token), 'A used link must not work twice.');
    }

    public function testForgotMailsOnlyForAnEmailAddress(): void
    {
        $before = (new EmailQueueModel())->count();

        (new AuthStaff())->forgot(self::LOGIN);
        self::assertSame($before, (new EmailQueueModel())->count(), 'A username must not send mail.');

        $message = (new AuthStaff())->forgot(strtoupper($this->email));
        self::assertSame(AuthStaff::RESET_SENT, $message);
        self::assertSame($before + 1, (new EmailQueueModel())->count());
    }
}
