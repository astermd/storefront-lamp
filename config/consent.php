<?php

/**
 * Consent controls shown at checkout.
 *
 * Every one of these is yours to change — wording, links, and which consents
 * appear — because jurisdiction and counsel differ. The defaults are drafted
 * to industry convention so a deployment that customises nothing still asks
 * for consent in a legally-shaped way; they are a starting point, not legal
 * advice, and every deployment is responsible for its own review.
 *
 * `blocking` decides whether the order can be placed without it. Exactly the
 * terms/privacy/telehealth consent is blocking by default; marketing and
 * transactional messaging never block anything.
 *
 * Marketing and transactional messaging are deliberately separate entries. A
 * visitor who declines marketing must still receive their shipping
 * notification, and bundling the two is the mistake this separation prevents.
 *
 * Changing any `html` below changes the version stamp recorded against every
 * later order, and existing records keep pointing at the wording they were
 * shown. The stamp is a hash of the copy, so editing a comma is a new version
 * without anyone having to remember to bump a number.
 *
 * `emr_field` sends the answer to the buyer's lead (opportunity) on the AsterMD
 * platform when an order is placed: `marketing_consent` or
 * `communication_consent`, or leave it out to keep a consent local. The
 * platform has no SMS-only field — `marketing_consent` covers promotional
 * email and SMS, `communication_consent` order and care messages. A decline is
 * sent as `false`. Nothing is sent while AsterMD analytics is off.
 */
return [
    'consents' => [
        [
            'key' => 'terms',
            'label' => 'Terms, privacy, and telehealth consent',
            'html' => 'I agree to the <a href="/terms/" class="underline">Terms of Service</a>, <a href="/privacy/" class="underline">Privacy Policy</a>, and <a href="/telehealth-consent/" class="underline">Telehealth Consent</a>.',
            'blocking' => true,
            'links' => ['/terms/', '/privacy/', '/telehealth-consent/'],
        ],
        [
            'key' => 'marketing',
            'label' => 'Marketing communications',
            'html' => 'I agree to receive marketing emails about products and offers.',
            'blocking' => false,
            'links' => [],
            'emr_field' => 'marketing_consent',
        ],
        [
            'key' => 'transactional_sms',
            'label' => 'Transactional messaging',
            'html' => 'I agree to receive order and care messages by SMS. Message and data rates may apply.',
            'blocking' => false,
            'links' => [],
            'emr_field' => 'communication_consent',
        ],
    ],
];
