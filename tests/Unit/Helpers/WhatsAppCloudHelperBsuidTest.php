<?php

namespace Funnelchat\WapiGateway\Tests\Unit\Helpers;

use Funnelchat\WapiGateway\Helpers\WhatsAppCloudHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WhatsAppCloudHelperBsuidTest extends TestCase
{
    #[DataProvider('bsuidProvider')]
    public function test_recognises_business_scoped_user_ids(string $identifier): void
    {
        $this->assertTrue(WhatsAppCloudHelper::isBsuid($identifier), $identifier . ' should be a BSUID');
    }

    public static function bsuidProvider(): array
    {
        return [
            'standard' => ['CO.1021346770783737'],
            'enterprise infix' => ['US.ENT.11815799212886844830'],
            'argentina' => ['AR.884412093019283'],
            'brazil' => ['BR.7761230981230981'],
            // "alphanumeric id" per Meta's spec, not digits-only.
            'alphanumeric id' => ['MX.a91Bc02Df77'],
            'enterprise alphanumeric id' => ['GB.ENT.9f2A81bC04'],
            // Meta's ids have no fixed length; a short one is still a BSUID.
            'short id' => ['CL.7'],
            // Callers hand us whatever the webhook stored, padding included.
            'surrounding whitespace is tolerated' => ['  CO.1021346770783737  '],
            // The three cases below are what `conversations` already routes as
            // non-phone. If this side read them as phones they would go out in
            // `to` and fail — see the alignment note on isBsuid().
            'lowercase country code' => ['co.1021346770783737'],
            'mixed case country code' => ['Co.1021346770783737'],
            // The segment list is open: `ENT` is the variant seen so far, not
            // the only one a portfolio may emit.
            'unknown portfolio segment' => ['US.XYZ.11815799212886844830'],
            'lowercase ent infix' => ['US.ent.11815799212886844830'],
            'two extra segments' => ['US.ENT.PORTFOLIO.11815799212886844830'],
            'id exactly 128 chars' => ['CO.' . str_repeat('a', 128)],
        ];
    }

    /**
     * The negative half carries the real risk. A phone classified as a BSUID
     * moves the destination from `to` to `recipient`, and the send fails for the
     * ~97% of traffic that is a plain phone number. Every shape a phone reaches
     * this SDK in must stay out.
     */
    #[DataProvider('notBsuidProvider')]
    public function test_never_classifies_a_phone_or_other_input_as_a_bsuid(?string $identifier): void
    {
        $this->assertFalse(WhatsAppCloudHelper::isBsuid($identifier), var_export($identifier, true) . ' must not be a BSUID');
    }

    public static function notBsuidProvider(): array
    {
        return [
            // --- real phone numbers, in every shape they reach the SDK ---
            'argentine mobile' => ['5491123456789'],
            'colombian mobile' => ['573001234567'],
            'brazilian mobile' => ['5511998765432'],
            'us number' => ['12025550123'],
            'e164 with plus' => ['+5491123456789'],
            'spaced' => ['+54 911 2345 6789'],
            'dashed' => ['+1-202-555-0123'],
            'parenthesised' => ['+1 (202) 555-0123'],
            'wa_id style' => ['5491123456789@c.us'],
            'short code' => ['10000'],
            // A phone with a decimal-looking dot must not sneak through: the
            // country-code half is digits, not two uppercase letters.
            'digits around a dot' => ['54.911234567'],

            // --- near misses on the BSUID shape itself ---
            'three letter prefix' => ['COL.1021346770783737'],
            'one letter prefix' => ['C.1021346770783737'],
            'no country code' => ['1021346770783737.11815799212886844830'],
            'no dot' => ['CO1021346770783737'],
            'empty id' => ['CO.'],
            'only country code' => ['CO'],
            'empty middle segment' => ['CO..1021346770783737'],
            'trailing dot' => ['CO.1021346770783737.'],
            'hyphen in id' => ['CO.1021346-770783737'],
            'underscore in id' => ['CO.1021346_770783737'],
            'trailing junk' => ['CO.1021346770783737@lid'],
            // Meta caps the id at 128 alphanumeric chars, and the `conversations`
            // mirror enforces the same bound.
            'id longer than 128 chars' => ['CO.' . str_repeat('a', 129)],
            'leading junk' => ['wa:CO.1021346770783737'],

            // --- degenerate input ---
            'empty string' => [''],
            'whitespace only' => ['   '],
            'null' => [null],
        ];
    }

    /**
     * `conversations` accepts Z-API's `@lid` in its mirror
     * (ContactService::isNonPhoneIdentifier); the Cloud API has no such concept,
     * so a bare `@lid` identifier is not a BSUID here. Pinned so the two rules
     * do not quietly drift into each other.
     */
    public function test_zapi_lid_identifiers_are_not_bsuids_on_the_cloud_api(): void
    {
        $this->assertFalse(WhatsAppCloudHelper::isBsuid('182736451928374@lid'));
    }

    /**
     * The BSUID half of ContactService::isNonPhoneIdentifier() in `conversations`,
     * copied verbatim. That rule is upstream of this one: it is what decides a
     * contact has no phone and parks the BSUID in `whatsapp_id`, which is then
     * handed back here as the send destination.
     */
    private const MIRROR_PATTERN = '/^[A-Za-z]{2}\.(?:[A-Za-z0-9]+\.)*[A-Za-z0-9]{1,128}$/';

    /**
     * The drift guard. Anything the mirror routes as a non-phone must be
     * addressable here, or it ships in `to` and the send fails — the exact bug
     * BSUID support exists to remove. isBsuid() is allowed to be *more*
     * permissive than the mirror, never less.
     *
     * If this fails, the two repos disagree about what a BSUID is; fix the rule
     * rather than the assertion.
     */
    public function test_is_at_least_as_permissive_as_the_conversations_mirror(): void
    {
        $corpus = array_merge(
            array_column(self::bsuidProvider(), 0),
            array_filter(array_column(self::notBsuidProvider(), 0)),
            ['US.PORTFOLIO.99', 'gb.ent.7', 'ZZ.aA0.bB1.cC2', 'CO.' . str_repeat('9', 128)],
        );

        foreach ($corpus as $identifier) {
            if (preg_match(self::MIRROR_PATTERN, trim((string) $identifier)) !== 1) {
                continue; // the mirror reads it as a phone; so may we
            }
            $this->assertTrue(
                WhatsAppCloudHelper::isBsuid($identifier),
                sprintf('%s is a non-phone in `conversations` but not a BSUID here — it would ship in `to` and fail', var_export($identifier, true)),
            );
        }
    }
}
