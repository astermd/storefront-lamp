<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Checkout;

use AsterMD\Storefront\Emr\VerificationGateway;

/**
 * The server's answer on whether a submitted checkout form can become an
 * order.
 *
 * `[13.5]` records what the old storefront checked: that each field was
 * "present and trimmed", and nothing else. An address with no email shape, a
 * phone that was three digits, a state spelled `NYC` and a four-digit ZIP all
 * reached the payment provider, which rejected some of them with messages
 * written for a developer and accepted others into a record no parcel could be
 * sent to. This class is that gap closed: shape checks that a buyer can act on,
 * expressed in their words rather than the provider's (`[25.6]`).
 *
 * **The gateway is a parameter, not a constructor dependency.** The EMR-backed
 * email check is a capability this deployment does not currently have — the
 * whole `verification()` resource is refused for its credential — so the
 * branch has to be visible at the call site rather than hidden behind a field.
 * When the check cannot be run it returns null, and null never produces an
 * error: a third-party outage must not stop a legitimate order (`[20.1]`).
 * Every rule here therefore stands on its own without the EMR ever answering.
 */
final class BuyerValidator
{
    /**
     * The shape an email must have, kept identical to
     * {@see \AsterMD\Storefront\Forms\AnswerValidator}'s.
     *
     * Deliberately looser than `FILTER_VALIDATE_EMAIL`, which disagrees with
     * the hosted intake engine about real addresses. An address the intake
     * accepted two steps ago must not be refused here — being told at the
     * payment step that an email typed earlier is invalid is the worst place
     * in the funnel to disagree with ourselves (`[28.3]`).
     */
    private const string EMAIL_SHAPE = '/^[^\s@]+@[^\s@]+\.[^\s@]+$/';

    /** A US ZIP, five digits with an optional +4 (`[13.1]`). */
    private const string POSTAL_SHAPE = '/^\d{5}(-\d{4})?$/';

    /** The fewest digits a reachable US number can have. */
    private const int PHONE_DIGITS = 10;

    /**
     * Every territory this storefront will ship to: the fifty states, the
     * District of Columbia, and the five inhabited territories that use the
     * same postal system.
     *
     * `[13.36]` is a declared gap — the country is hardcoded and this set is
     * US-only — and the point of validating against it rather than accepting
     * any two letters is to make that gap *visible*. A buyer in Ontario is
     * told plainly that we ship within the United States only, instead of
     * being taken through payment to an order that can never be delivered.
     *
     * @var list<string>
     */
    private const array TERRITORIES = [
        'AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'DC', 'FL',
        'GA', 'HI', 'ID', 'IL', 'IN', 'IA', 'KS', 'KY', 'LA', 'ME',
        'MD', 'MA', 'MI', 'MN', 'MS', 'MO', 'MT', 'NE', 'NV', 'NH',
        'NJ', 'NM', 'NY', 'NC', 'ND', 'OH', 'OK', 'OR', 'PA', 'RI',
        'SC', 'SD', 'TN', 'TX', 'UT', 'VT', 'VA', 'WA', 'WV', 'WI',
        'WY', 'AS', 'GU', 'MP', 'PR', 'VI',
    ];

    /**
     * Every problem with this submission, keyed by the field that has it.
     *
     * Keyed rather than listed so the template can put each message beside its
     * own control and point the input's `aria-describedby` at it: an error
     * summary that does not say which box is wrong is an error summary a
     * screen-reader user cannot act on.
     *
     * At most one message per field. A blank email is not also badly shaped —
     * it is blank, and telling someone both at once is telling them neither.
     *
     * @return array<string, string> checkout field name → the buyer-facing message
     */
    public static function validate(BuyerDetails $details, VerificationGateway $verification): array
    {
        $errors = [];

        foreach (self::required($details) as $field => $message) {
            $errors[$field] = $message;
        }

        if (!isset($errors['email']) && preg_match(self::EMAIL_SHAPE, $details->email) !== 1) {
            $errors['email'] = 'Enter an email address in the form name@example.com.';
        }

        if (!isset($errors['phone']) && strlen($details->phone) < self::PHONE_DIGITS) {
            $errors['phone'] = 'Enter a phone number with at least 10 digits, including the area code.';
        }

        if (!isset($errors['territory'])) {
            $territory = self::territoryError($details->territory);
            if ($territory !== null) {
                $errors['territory'] = $territory;
            }
        }

        if (!isset($errors['postal_code']) && preg_match(self::POSTAL_SHAPE, $details->postalCode) !== 1) {
            $errors['postal_code'] = 'Enter a ZIP code as five digits, or five plus four.';
        }

        // Only once the address is locally well-formed, and only when the
        // capability is actually granted: a check that cannot run must not
        // cost every buyer a round trip on every render.
        if (!isset($errors['email']) && $verification->isEnabled()
            && $verification->emailIsDeliverable($details->email) === false) {
            $errors['email'] = 'We could not deliver mail to that address. Check it for a typo.';
        }

        return $errors;
    }

    /**
     * The fields that must carry something, with the sentence each gets when
     * it does not.
     *
     * Whitespace is not an answer: {@see BuyerDetails::fromSubmitted()} has
     * already trimmed, so a box holding only spaces arrives here empty and is
     * reported as the omission it is.
     *
     * @return array<string, string>
     */
    private static function required(BuyerDetails $details): array
    {
        $messages = [
            'first_name' => 'Enter your first name.',
            'last_name' => 'Enter your last name.',
            'email' => 'Enter your email address.',
            'phone' => 'Enter your phone number.',
            'address_line' => 'Enter your street address.',
            'city' => 'Enter your city.',
            'territory' => 'Choose your state.',
            'postal_code' => 'Enter your ZIP code.',
        ];

        $missing = [];
        foreach ($details->toArray() as $field => $value) {
            if ($value === '' && isset($messages[$field])) {
                $missing[$field] = $messages[$field];
            }
        }

        return $missing;
    }

    /** Why this territory cannot be shipped to, or null when it can. */
    private static function territoryError(string $territory): ?string
    {
        if (preg_match('/^[A-Z]{2}$/', $territory) !== 1) {
            return 'Enter your state as its two-letter code, such as NY.';
        }

        if (!in_array($territory, self::TERRITORIES, true)) {
            return 'We ship within the United States only, so this must be a US state or territory.';
        }

        return null;
    }
}
