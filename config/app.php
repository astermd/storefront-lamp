<?php

/**
 * Site configuration. `$env` carries the values loaded from .env.
 * Machine-managed blocks (channel binding, payment provider) are merged by
 * `bin/console theme:sync` — everything else is yours.
 */
return [
    'name' => 'AsterMD',
    'url' => $env['APP_URL'] ?? 'http://localhost:8080',
    'env' => $env['APP_ENV'] ?? 'production',

    'database' => [
        'driver' => $env['DB_DRIVER'] ?? 'sqlite',
        'database' => $env['DB_DATABASE'] ?? 'storage/database/app.sqlite',
        'host' => $env['DB_HOST'] ?? '127.0.0.1',
        'port' => $env['DB_PORT'] ?? null,
        'username' => $env['DB_USERNAME'] ?? null,
        'password' => $env['DB_PASSWORD'] ?? null,
    ],

    'emr' => [
        'base_host' => $env['ASTERMD_BASE_HOST'] ?? 'api.astermd.com',
        'channel_id' => $env['ASTERMD_CHANNEL_ID'] ?? null,
    ],

    'session' => [
        // The analytics-session cookie and the single canonical resume
        // parameter. Both carry the same value; the cookie is HttpOnly, so a
        // session the browser has lost the cookie for can only be restored
        // through the resume parameter.
        'cookie' => 'amd_session',
        'resume_param' => 'amd_session',
        'lifetime_days' => 30,
        // Paths that never mint a session: probes and machine endpoints are
        // not visitors.
        'exclude_paths' => ['/health/'],
        // EMR analytics sessions. Off under the test suite so no test run can
        // reach the network or write to the dev EMR; tests that exercise the
        // session flow inject their own gateway.
        'analytics' => ($env['APP_ENV'] ?? 'production') !== 'test',
    ],

    'attribution' => [
        'payload_param' => '_amd',
        // Rotating the key: move the live value into AMD_TRACKING_KEY_PREVIOUS,
        // put the new one in AMD_TRACKING_KEY, deploy, and drop the previous
        // value once no live links can still carry it. Links already sent in
        // emails and ads keep working through the overlap.
        'key' => $env['AMD_TRACKING_KEY'] ?? null,
        'previous_key' => $env['AMD_TRACKING_KEY_PREVIOUS'] ?? null,
    ],

    'seo' => [
        // The site's own name, used in the title template and in the social
        // card. Kept separate from `app.name` because a storefront can be
        // branded differently from the application that serves it.
        'site_name' => 'AsterMD',

        // `%s` is the page's own title. A page that supplies none renders
        // `default_title` verbatim, without the template, so the homepage
        // does not read "AsterMD — AsterMD".
        'title_template' => '%s — AsterMD',
        'default_title' => 'AsterMD — A Smarter Path to Longevity & Wellness',

        // The last resort in the `[24.4]` description chain: a per-page
        // override, then the product's own description, then this. It is the
        // last resort rather than a nicety because every product synced from
        // the EMR today carries an empty description, so without this the
        // product pages render `<meta name="description" content="">`.
        'default_description' => 'Connect with licensed physicians in minutes. Personalized care plans, seamless prescriptions, and ongoing support for your well-being.',

        // Path under /assets/ used as the social sharing image when a page
        // has no image of its own. Null disables the image half of the card
        // rather than emitting a broken URL.
        'social_image' => null,

        // `[24.7]`'s master switch. When true, EVERY route is marked
        // noindex — header and meta both — regardless of any per-route rule,
        // so a staging deployment cannot leak into an index through the
        // misconfiguration of one page. Defaults on outside production for
        // exactly that reason.
        'discourage_indexing' => ($env['APP_ENV'] ?? 'production') !== 'production',

        // Per-route directives (`[24.4]`). Keys are canonical paths; a path
        // listed here is excluded from the sitemap too (`[24.6]`), so the
        // funnel cannot be indexed and cannot be advertised from one place
        // while being hidden from the other. Prefix entries end in `*`.
        'robots_rules' => [
            '/intake/*' => 'noindex, nofollow',
            '/verify/' => 'noindex, nofollow',
            '/checkout/*' => 'noindex, nofollow',
            '/upsell/*' => 'noindex, nofollow',
            '/thank-you/' => 'noindex, nofollow',
            '/not-eligible/' => 'noindex, nofollow',
            '/cart/*' => 'noindex, nofollow',
            '/health/' => 'noindex',
        ],

        // `[24.8]`. Each emitter can be turned off independently, because
        // what is publishable differs by client and by territory.
        'structured_data' => [
            'organisation' => true,
            'website' => true,
            'product' => true,
            'breadcrumbs' => true,

            // `[24.10]`, and the default is deliberate. A prescription
            // product carries claims and availability constraints that vary
            // by jurisdiction, and the rule says to default to the
            // conservative option — so an `rx` product publishes no
            // structured data at all until a deployment decides its own
            // territory allows it.
            'rx' => false,
        ],

        // The URL a `SearchAction` submits a query to, or null to publish no
        // search action at all.
        //
        // **Null as shipped, and that is a decision rather than an omission.**
        // `[24.8]` names a site search action among the structured data to
        // emit, and the emitter is built and tested — but this storefront has
        // no search endpoint. There is no search route, no search control, and
        // the treatments listing filters in the browser without reading a query
        // parameter. Publishing the action anyway would tell a search engine
        // that a query submitted here returns matching results, when what comes
        // back is the unfiltered listing.
        //
        // That is the same class of published lie `[24.9]` forbids for prices
        // and `[24.10]` guards against for prescription claims, so it takes the
        // same conservative default. A deployment that builds real search sets
        // this to its own template — `/search/?q={search_term_string}` — and the
        // action appears with no further change.
        'search_url_template' => null,

        // `[24.13]`. Whether a client wants their catalog ingested by answer
        // engines is a commercial decision, not a technical default, so it is
        // configuration. `true` allows the named crawlers; `false` disallows
        // them in robots.txt while leaving ordinary search crawlers alone.
        'ai_crawlers' => [
            'allow' => true,
            'agents' => ['GPTBot', 'ClaudeBot', 'PerplexityBot', 'Google-Extended', 'CCBot'],
        ],
    ],

    'features' => [
        'shipping_selector' => false,
        'wallets' => false,
        'google_maps_api_key' => $env['GOOGLE_MAPS_API_KEY'] ?? null,

        // Email and address checks against the EMR at checkout. Off because
        // the credential this storefront ships with is refused for that whole
        // resource, and a check that always fails is worse than none: it would
        // either block real buyers or teach an operator to ignore it. Checkout
        // validates format locally either way, so turning this on adds a
        // second opinion rather than supplying the only one.
        'emr_verification' => filter_var($env['EMR_VERIFICATION'] ?? false, FILTER_VALIDATE_BOOL),
    ],

    'debug' => [
        // Writes every EMR and payment-provider call to storage/logs/, request
        // and response, **verbatim and unredacted** — which is the entire
        // point of it, because a redacted transcript cannot be replayed by
        // hand and replaying the exact bytes is how a provider disagreement
        // gets settled.
        //
        // While it is on, those files hold primary account numbers, security
        // codes, expiry dates, live bearer tokens and the OAuth client secret
        // in clear text, next to the buyer's name, address and telephone
        // number. That is cardholder data at rest, which `[15.8]` forbids this
        // application's own storage from holding — so this is a local
        // debugging switch against sandbox test cards, and **never something a
        // deployment taking real payments may enable.**
        //
        // `bin/console config:validate` reports it as an error, not a warning,
        // so nobody ships with it on by accident. The files land under
        // storage/, which is gitignored, so a transcript cannot be committed
        // by accident either.
        'wire_log' => filter_var($env['WIRE_LOG'] ?? false, FILTER_VALIDATE_BOOL),
    ],
];
