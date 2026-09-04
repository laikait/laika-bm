<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Core\Exceptions\SchemaException;
use LBM\Model\EmailTemplateModel;
use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Model\Contract\SchemaAbstract;
use Laika\Service\Uid;

class EmailTemplateSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'email_templates';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    /**
     * @var string The Slug The First Version Of This Seed Wrote
     *
     * Nothing ever asked for it. `AuthClient::register()` calls
     * notify('client-welcome', ...), so the one seeded template was an orphan
     * and the welcome email had nothing to render. See migrateLegacy().
     */
    private const LEGACY_SLUG = 'new-client';

    /**
     * @var string The Body That Orphan Was Seeded With
     *
     * Stored through htmlspecialchars(), so it would have rendered as visible
     * `&lt;p&gt;` tags, and written with single-brace `{placeholders}` the
     * renderer does not recognise. Matched byte for byte before overwriting, so
     * an operator who rewrote the copy keeps it.
     */
    private const LEGACY_BODY = '&lt;p&gt;Hello {client_name}&lt;/p&gt;&lt;p&gt;Welcome to {company_name}. '
        . 'Your Registration is successful. Your Credentials:&lt;/p&gt;&lt;p&gt;Email: {client_email}&lt;/p&gt;'
        . '&lt;p&gt;Username: {client_username}&lt;/p&gt;&lt;p&gt;Password: ********* (Protected)&lt;/p&gt;';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->id('et_id');
            $t->uid('uid');
            $t->string('slug')->comment('Example: client-welcome, invoice-created');
            $t->string('name', 100)->comment('Example: client_welcome, invoice_created');
            $t->string('subject');
            $t->longText('body');
            $t->json('variables')->comment('Documentation of available {{variables}}');
            $t->enum('is_active', ['yes', 'no'])->default('yes');
            $t->timestamps();

            // Indexes
            $t->unique('slug');
            $t->unique('name');
            $t->index('is_active');
        });
    }

    /**
     * Seed Every Template The Application Actually Sends
     *
     * ------------------------------------------------------------------------
     * Why this seeds per slug rather than guarding on a row count
     * ------------------------------------------------------------------------
     * The first version returned early when the table held any row at all, and
     * wrote exactly one template - under a slug nothing requests. The result was
     * an install where mail delivery worked perfectly and four of the five
     * customer-facing messages had nothing to render:
     *
     *   password-reset    silently nothing. The visitor is told a reset link is
     *                     on its way and none ever arrives.
     *   client-welcome    silently nothing on registration.
     *   invoice-reminder  silently nothing. Invoices are never chased.
     *   invoice-created   the admin "send invoice" button reports an error; the
     *                     automatic send from InvoiceGenerateJob is swallowed.
     *
     * Three of those four are caught and discarded by design, so nothing in the
     * application ever said a word about it.
     *
     * Seeding per slug repairs existing installs on the next app:migrate as well
     * as new ones, which a count() guard could never do: the table already had a
     * row, so it would have returned early forever.
     *
     * A slug is skipped when it already exists, and also when its `name` is
     * taken - both columns are UNIQUE, and an operator's own row wins.
     * @return void
     */
    public function seed(): void
    {
        $this->migrateLegacy();

        foreach ($this->templates() as $slug => $template) {
            if ((new EmailTemplateModel())->where(['slug' => $slug])->exists()) {
                continue;
            }

            if ((new EmailTemplateModel())->where(['name' => $template['name']])->exists()) {
                continue;
            }

            $row = Uid::stamp([
                'slug'       =>  $slug,
                'name'       =>  $template['name'],
                'subject'    =>  $template['subject'],
                'body'       =>  $template['body'],
                'variables'  =>  json_encode($template['variables'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
                'is_active'  =>  'yes',
                'created_at' =>  date('Y-m-d H:i:s'),
                'updated_at' =>  date('Y-m-d H:i:s'),
            ]);

            try {
                (new EmailTemplateModel())->insert($row);
            } catch (\Throwable $e) {
                throw new SchemaException(
                    "Insert Failed Into [{$this->table}] for slug [{$slug}]. " . $e->getMessage(),
                    (int) $e->getCode(),
                    $e
                );
            }
        }
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Re-point The Orphaned `new-client` Row At The Slug That Is Actually Sent
     *
     * Re-slugged rather than left beside a new row, for two reasons. It keeps
     * `name` unique - the orphan is already called client_welcome, so a fresh
     * insert under that name would collide. And an install that has been running
     * for months ends up with one welcome template rather than two, one of which
     * quietly does nothing.
     *
     * Subject and body are overwritten only when they still match the original
     * seed byte for byte. An operator who rewrote the copy through the admin
     * screen keeps it; a template nobody ever touched - and which could not have
     * worked, since its placeholders use a syntax the renderer does not parse -
     * is replaced.
     * @return void
     */
    private function migrateLegacy(): void
    {
        $legacy = (new EmailTemplateModel())->where(['slug' => self::LEGACY_SLUG])->first();

        if (!is_array($legacy) || $legacy === []) {
            return;
        }

        // Somebody has already made a real client-welcome template. Leave the
        // orphan alone rather than colliding with it - it is deletable from the
        // admin screen now.
        if ((new EmailTemplateModel())->where(['slug' => 'client-welcome'])->exists()) {
            return;
        }

        $template = $this->templates()['client-welcome'];
        $data = ['slug' => 'client-welcome', 'updated_at' => date('Y-m-d H:i:s')];

        if (trim((string) ($legacy['body'] ?? '')) === self::LEGACY_BODY) {
            $data['subject'] = $template['subject'];
            $data['body'] = $template['body'];
            $data['variables'] = json_encode($template['variables'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        }

        (new EmailTemplateModel())
            ->where(['et_id' => (int) $legacy['et_id']])
            ->update($data);
    }

    /**
     * The Templates, Keyed By The Slug The Code Requests
     *
     * ------------------------------------------------------------------------
     * The placeholder syntax is {{name}} and nothing else
     * ------------------------------------------------------------------------
     * `Mail\Templater::substitute()` recognises `{{name}}` only. A single-brace
     * `{name}` is not a placeholder, it is text, and it reaches the customer
     * with the braces showing - which is how the first seed shipped.
     *
     * Bodies are stored as real HTML and are NOT escaped on the way in. The body
     * is markup the operator writes and the renderer sends as the message body;
     * escaping it here is what turned the first seed into visible tags.
     * Placeholder *values* are escaped at substitution time, which is where that
     * belongs - the operator's markup is trusted, the data going into it is not.
     *
     * `variables` is a JSON object of placeholder => description, because the
     * admin screen iterates it as `key => description`. The first seed stored a
     * JSON list, so that panel showed `{{ 0 }}`, `{{ 1 }}`, `{{ 2 }}`.
     *
     * Every template may also use the defaults `Templater::withDefaults()` adds:
     * app_name, app_host, app_email, client_area, year and date.
     * @return array<string,array{name:string,subject:string,body:string,variables:array<string,string>}>
     */
    private function templates(): array
    {
        return [
            'client-welcome' => [
                'name'    =>  'client_welcome',
                'subject' =>  'Welcome to {{app_name}}',
                'body'    =>  '<p>Hello {{first_name}},</p>'
                    . '<p>Your account with {{app_name}} is set up and ready to use.</p>'
                    . '<p>You can sign in at <a href="{{client_area}}">{{client_area}}</a> '
                    . 'using <strong>{{email}}</strong> and the password you chose.</p>'
                    . '<p>If anything looks wrong, reply to this message and we will sort it out.</p>'
                    . '<p>- {{app_name}}</p>',
                'variables' => [
                    'first_name' =>  'The account holder\'s first name.',
                    'last_name'  =>  'The account holder\'s last name.',
                    'email'      =>  'The address the account was registered with.',
                ],
            ],

            'password-reset' => [
                'name'    =>  'password_reset',
                'subject' =>  'Reset your {{app_name}} password',
                'body'    =>  '<p>Hello {{first_name}},</p>'
                    . '<p>Somebody asked to reset the password on your {{app_name}} account. '
                    . 'If that was you, use the link below.</p>'
                    . '<p><a href="{{reset_url}}">Choose a new password</a></p>'
                    . '<p>The link works once and expires in {{expires_in}}.</p>'
                    . '<p>If it was not you, nothing needs doing - your password has not '
                    . 'changed and the link can be ignored.</p>'
                    . '<p>- {{app_name}}</p>',
                'variables' => [
                    'first_name' =>  'The account holder\'s first name.',
                    'last_name'  =>  'The account holder\'s last name.',
                    'reset_url'  =>  'The single-use link that opens the reset form.',
                    'expires_in' =>  'How long the link stays valid, in words.',
                ],
            ],

            'invoice-created' => [
                'name'    =>  'invoice_created',
                'subject' =>  'Invoice {{invoice_number}} from {{app_name}}',
                'body'    =>  '<p>Hello {{first_name}},</p>'
                    . '<p>Invoice <strong>{{invoice_number}}</strong> for '
                    . '<strong>{{total}}</strong> is ready, and is due on {{due_date}}.</p>'
                    . '<p>You can view and pay it in your account: '
                    . '<a href="{{client_area}}">{{client_area}}</a></p>'
                    . '<p>- {{app_name}}</p>',
                'variables' => [
                    'first_name'     =>  'The client\'s first name.',
                    'invoice_number' =>  'The invoice reference. Example: INV-000123.',
                    'total'          =>  'The invoice total, already formatted with its currency.',
                    'due_date'       =>  'The date payment is due, already formatted.',
                ],
            ],

            'invoice-reminder' => [
                'name'    =>  'invoice_reminder',
                'subject' =>  '{{subject}}',
                'body'    =>  '<p>Hello {{first_name}},</p>'
                    . '<p>A reminder about invoice <strong>{{invoice_number}}</strong>, '
                    . 'due on {{due_date}}.</p>'
                    . '<p>Outstanding balance: <strong>{{balance}}</strong> of {{total}}.</p>'
                    . '<p>You can settle it in your account: '
                    . '<a href="{{client_area}}">{{client_area}}</a></p>'
                    . '<p>If you have already paid, please ignore this message.</p>'
                    . '<p>- {{app_name}}</p>',
                'variables' => [
                    'first_name'     =>  'The client\'s first name.',
                    'last_name'      =>  'The client\'s last name.',
                    'invoice_number' =>  'The invoice reference.',
                    'balance'        =>  'What is still owed, already formatted with its currency.',
                    'total'          =>  'The invoice total, already formatted.',
                    'due_date'       =>  'The date payment is due, already formatted.',
                    'days'           =>  'How many days before or after the due date this reminder is.',
                    'subject'        =>  'The subject the job chose. Used as the subject line.',
                ],
            ],

            // The two dunning messages. A suspension the customer is never told
            // about is a support ticket by lunchtime, and a restoration they are
            // never told about is the same ticket a second time - they have paid
            // and have no way of knowing whether it landed.
            'service-suspended' => [
                'name'    =>  'service_suspended',
                'subject' =>  'Your {{service_name}} service has been suspended',
                'body'    =>  '<p>Hello {{first_name}},</p>'
                    . '<p>Your <strong>{{service_name}}</strong> service has been '
                    . 'suspended because a payment is outstanding.</p>'
                    . '<p>{{reason}}</p>'
                    . '<p>Nothing has been deleted. As soon as the invoice is '
                    . 'settled the service is switched back on, normally within '
                    . 'a few minutes.</p>'
                    . '<p>You can pay in your account: '
                    . '<a href="{{client_area}}">{{client_area}}</a></p>'
                    . '<p>If you believe this is a mistake, or you have already '
                    . 'paid, please reply to this message.</p>'
                    . '<p>- {{app_name}}</p>',
                'variables' => [
                    'first_name'   =>  'The client\'s first name.',
                    'last_name'    =>  'The client\'s last name.',
                    'service_name' =>  'The product this service was bought from.',
                    'domain'       =>  'The domain the service is for, if it has one.',
                    'reason'       =>  'Which invoice is outstanding. Written for the client to read.',
                ],
            ],

            'service-restored' => [
                'name'    =>  'service_restored',
                'subject' =>  'Your {{service_name}} service is back online',
                'body'    =>  '<p>Hello {{first_name}},</p>'
                    . '<p>Thank you - your payment has been received and your '
                    . '<strong>{{service_name}}</strong> service is active again.</p>'
                    . '<p>Everything is as you left it. Nothing was removed while '
                    . 'it was suspended.</p>'
                    . '<p>- {{app_name}}</p>',
                'variables' => [
                    'first_name'   =>  'The client\'s first name.',
                    'last_name'    =>  'The client\'s last name.',
                    'service_name' =>  'The product this service was bought from.',
                    'domain'       =>  'The domain the service is for, if it has one.',
                ],
            ],

            // The two end-of-life messages. Cancelling and terminating are
            // different acts and need different words: one says the billing has
            // stopped, the other says the data is gone. A single template
            // covering both would have to be vague about which happened, and
            // vague is the one thing this message must not be.
            'service-cancelled' => [
                'name'    =>  'service_cancelled',
                'subject' =>  'Your {{service_name}} service has been cancelled',
                'body'    =>  '<p>Hello {{first_name}},</p>'
                    . '<p>Your <strong>{{service_name}}</strong> service is set to end on '
                    . '<strong>{{ends_on}}</strong>, and you will not be billed for it again.</p>'
                    . '<p>{{reason}}</p>'
                    . '<p>Nothing has been deleted yet. If you have anything on it you '
                    . 'want to keep, please take a copy before that date.</p>'
                    . '<p>If this was not what you meant, reply to this message and we '
                    . 'will put it back.</p>'
                    . '<p>- {{app_name}}</p>',
                'variables' => [
                    'first_name'   =>  'The client\'s first name.',
                    'last_name'    =>  'The client\'s last name.',
                    'service_name' =>  'The product this service was bought from.',
                    'domain'       =>  'The domain the service is for, if it has one.',
                    'ends_on'      =>  'The date billing stops, already formatted.',
                    'reason'       =>  'Why it is being cancelled, if a reason was given.',
                ],
            ],

            'service-terminated' => [
                'name'    =>  'service_terminated',
                'subject' =>  'Your {{service_name}} service has been removed',
                'body'    =>  '<p>Hello {{first_name}},</p>'
                    . '<p>Your <strong>{{service_name}}</strong> service has now been '
                    . 'removed from our servers, along with the data on it.</p>'
                    . '<p>{{reason}}</p>'
                    . '<p>This cannot be undone. If you would like to start again, you '
                    . 'are welcome to place a new order at any time.</p>'
                    . '<p>- {{app_name}}</p>',
                'variables' => [
                    'first_name'   =>  'The client\'s first name.',
                    'last_name'    =>  'The client\'s last name.',
                    'service_name' =>  'The product this service was bought from.',
                    'domain'       =>  'The domain the service was for, if it had one.',
                    'reason'       =>  'Why it was removed, if a reason was given.',
                ],
            ],
        ];
    }
}
