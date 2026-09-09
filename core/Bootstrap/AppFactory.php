<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Bootstrap;

use AsterMD\Storefront\Catalog\CatalogProvider;
use AsterMD\Storefront\Database\ConnectionFactory;
use AsterMD\Storefront\Database\Migrator;
use AsterMD\Storefront\Domain\CartRules;
use AsterMD\Storefront\Domain\FunnelRouter;
use AsterMD\Storefront\Domain\ProductCatalog;
use AsterMD\Storefront\Emr\CartGateway;
use AsterMD\Storefront\Emr\CartMirror;
use AsterMD\Storefront\Emr\ClientFactory as EmrClientFactory;
use AsterMD\Storefront\Emr\EmrCartGateway;
use AsterMD\Storefront\Emr\EmrSessionGateway;
use AsterMD\Storefront\Emr\NullCartGateway;
use AsterMD\Storefront\Emr\NullSessionGateway;
use AsterMD\Storefront\Emr\SessionGateway;
use AsterMD\Storefront\Funnel\FlowDefinition;
use AsterMD\Storefront\Funnel\StepPreconditions;
use AsterMD\Storefront\Http\Controller\VerifyController;
use AsterMD\Storefront\Verification\EmrIdentityGateway;
use AsterMD\Storefront\Verification\IdentityGateway;
use AsterMD\Storefront\Verification\NullIdentityGateway;
use AsterMD\Storefront\Verification\VerificationStep;
use AsterMD\Storefront\Http\Controller\CartController;
use AsterMD\Storefront\Http\Controller\HealthController;
use AsterMD\Storefront\Http\Controller\IntakeController;
use AsterMD\Storefront\Http\Controller\NotEligibleController;
use AsterMD\Storefront\Http\Controller\ProductDetailController;
use AsterMD\Storefront\Http\Controller\ProductListController;
use AsterMD\Storefront\Http\Controller\RobotsController;
use AsterMD\Storefront\Http\Controller\SitemapController;
use AsterMD\Storefront\Http\Middleware\AttributionMiddleware;
use AsterMD\Storefront\Http\Middleware\CanonicalUrlMiddleware;
use AsterMD\Storefront\Http\Middleware\SeoMiddleware;
use AsterMD\Storefront\Http\Middleware\CsrfMiddleware;
use AsterMD\Storefront\Http\Middleware\JourneyStateMiddleware;
use AsterMD\Storefront\Http\Middleware\LazyMiddleware;
use AsterMD\Storefront\Http\Middleware\SessionMiddleware;
use AsterMD\Storefront\Http\Middleware\StepGuardMiddleware;
use AsterMD\Storefront\Http\Middleware\TemplateGlobalsMiddleware;
use AsterMD\Storefront\Http\TwigExtensions;
use AsterMD\Storefront\Forms\AnswerValidator;
use AsterMD\Storefront\Forms\Disqualification;
use AsterMD\Storefront\Forms\EmrIntakeGateway;
use AsterMD\Storefront\Forms\EmrLeadGateway;
use AsterMD\Storefront\Forms\IntakeGateway;
use AsterMD\Storefront\Forms\IntakeSession;
use AsterMD\Storefront\Forms\LeadGateway;
use AsterMD\Storefront\Forms\LeadWriter;
use AsterMD\Storefront\Forms\NullIntakeGateway;
use AsterMD\Storefront\Forms\NullLeadGateway;
use AsterMD\Storefront\Forms\RecordMapper;
use AsterMD\Storefront\Forms\RecordingIntakeGateway;
use AsterMD\Storefront\Forms\RuleEvaluator;
use AsterMD\Storefront\Forms\DefinitionCache;
use AsterMD\Storefront\Forms\EmrTeleformGateway;
use AsterMD\Storefront\Forms\NullTeleformGateway;
use AsterMD\Storefront\Checkout\CheckoutEventReporter;
use AsterMD\Storefront\Checkout\CheckoutService;
use AsterMD\Storefront\Checkout\Consents;
use AsterMD\Storefront\Checkout\DatabaseOrderRecorder;
use AsterMD\Storefront\Checkout\EmrCheckoutEventReporter;
use AsterMD\Storefront\Checkout\OrderBumps;
use AsterMD\Storefront\Checkout\OrderRecorder;
use AsterMD\Storefront\Checkout\PostChargeGuard;
use AsterMD\Storefront\Emr\EmrVerificationGateway;
use AsterMD\Storefront\Emr\NullVerificationGateway;
use AsterMD\Storefront\Emr\VerificationGateway;
use AsterMD\Storefront\Forms\TeleformGateway;
use AsterMD\Storefront\Forms\TeleformSource;
use AsterMD\Storefront\Http\Controller\CheckoutController;
use AsterMD\Storefront\Completion\Completion;
use AsterMD\Storefront\Http\Controller\ReceiptController;
use AsterMD\Storefront\Http\Controller\UpsellController;
use AsterMD\Storefront\Payment\AdapterRegistry;
use AsterMD\Storefront\Payment\PaymentAdapter;
use AsterMD\Storefront\Payment\RefusingTransport;
use AsterMD\Storefront\Payment\Vrio\VrioAdapter;
use AsterMD\Storefront\Payment\Vrio\VrioApiFactory;
use AsterMD\Storefront\Payment\Vrio\VrioCredentials;
use AsterMD\Storefront\Payment\Vrio\VrioWireLog;
use AsterMD\VrioClient\Http\CurlClient;
use AsterMD\VrioClient\Http\HttpClientInterface;
use AsterMD\Storefront\Journey\CartStore;
use AsterMD\Storefront\Journey\JourneyStore;
use AsterMD\Storefront\Journey\SessionOptions;
use AsterMD\Storefront\Http\Middleware\RetiredSessionMiddleware;
use AsterMD\Storefront\Upsell\Upsells;
use AsterMD\Storefront\Upsell\UpsellQueue;
use AsterMD\Storefront\Upsell\UpsellService;
use AsterMD\Storefront\Journey\SessionResolver;
use AsterMD\Storefront\Observability\BoundaryTimer;
use AsterMD\Storefront\Observability\CorrelationContext;
use AsterMD\Storefront\Observability\Instrumentation;
use AsterMD\Storefront\Observability\Reachability;
use AsterMD\Storefront\Repository\CheckoutAttemptRepository;
use AsterMD\Storefront\Repository\EventRepository;
use AsterMD\Storefront\Repository\OrderRepository;
use AsterMD\Storefront\Repository\RateLimitRepository;
use AsterMD\Storefront\Repository\SessionRepository;
use AsterMD\Storefront\Support\AssetManifest;
use AsterMD\Storefront\Retention\RetentionPolicy;
use AsterMD\Storefront\Seo\MetaResolver;
use AsterMD\Storefront\Seo\RobotsPolicy;
use AsterMD\Storefront\Support\Config;
use AsterMD\Storefront\Support\DatabaseRateLimiter;
use AsterMD\Storefront\Support\OperatorLog;
use AsterMD\Storefront\Support\RateLimiter;
use DI\Container;
use Dotenv\Dotenv;
use FastRoute\Dispatcher;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

/**
 * Wires the whole app together: config, PDO/migrator, Twig, the DI container,
 * the middleware pipeline, and error handling. This is the one place all of
 * those pieces are allowed to know about each other — everything else
 * receives its collaborators through the container instead of constructing
 * them itself.
 */
final class AppFactory
{
    /** Container id for the route probe published in {@see self::create()}. */
    private const string ROUTE_EXISTS = 'attribution.route_exists';

    /**
     * The middleware pipeline, OUTERMOST FIRST — this list is the documented
     * execution order and is covered by a test. Slim wraps LIFO, so
     * registration below iterates this list in reverse.
     */
    public const MIDDLEWARE_ORDER = [
        CanonicalUrlMiddleware::class,
        // Immediately inside canonicalisation and outside everything else.
        // The canonical URL it publishes has to be built from the
        // canonicalised path, so canonicalisation must already have run; and
        // `[24.2]`'s indexing directive has to reach every response the
        // application produces, including the ones the session, journey and
        // step-guard slots redirect away before a controller sees them.
        SeoMiddleware::class,
        SessionMiddleware::class,
        // Outside AttributionMiddleware, and the position is load-bearing
        // twice over. A browser applies `Set-Cookie` headers in the order it
        // receives them, and attribution adds its own on the way out — so a
        // clear written inside it would be overwritten by the very re-issue it
        // is cancelling. And reading the retirement flag after attribution has
        // run means a request that re-minted its session reads the *fresh*
        // journey, which carries no flag, so a re-mint is left alone instead of
        // being cleared into a mint loop.
        RetiredSessionMiddleware::class,
        AttributionMiddleware::class,
        CsrfMiddleware::class,
        JourneyStateMiddleware::class,
        StepGuardMiddleware::class,
        TemplateGlobalsMiddleware::class,
    ];

    /**
     * Builds and returns a ready-to-run Slim app for $rootDir.
     *
     * Error routing has three tiers, checked in this order: a 404 gets the
     * themed error-404 page (registered explicitly, since Slim's routing
     * middleware throws HttpNotFoundException before any handler runs); any
     * other HttpSpecializedException (405, 400, 403, ...) gets the generic
     * themed error-http page and is deliberately *not* logged, since these
     * are malformed/disallowed requests rather than application faults;
     * anything else is an unexpected crash — it's logged to storage/logs
     * with a request ID, and that same ID is shown on the themed 500 page so
     * a user's bug report can be matched back to the log line.
     *
     * $containerOverrides (container id => value) is a test seam only: it is
     * applied after every default definition and immediately before the
     * container is handed to Slim, so a test can substitute a fake gateway or
     * an already-migrated PDO without a second bootstrap path. Production
     * callers pass nothing.
     *
     * @param array<string, mixed> $containerOverrides
     */
    public static function create(string $rootDir, array $containerOverrides = []): App
    {
        Dotenv::createImmutable($rootDir)->safeLoad();
        $config = Config::load($rootDir . '/config', Config::environment());

        if (!is_dir($rootDir . '/storage/logs')) {
            mkdir($rootDir . '/storage/logs', 0775, true);
        }

        $container = new Container();
        $container->set(Config::class, $config);
        $container->set('rootDir', $rootDir);
        $container->set(ConnectionFactory::class, static fn (): ConnectionFactory => new ConnectionFactory(
            (array) $config->get('app.database', []),
            $rootDir,
        ));
        $container->set(\PDO::class, static fn (Container $c): \PDO => $c->get(ConnectionFactory::class)->create());
        $container->set(Migrator::class, static fn (Container $c): Migrator => new Migrator(
            $c->get(\PDO::class),
            $c->get(ConnectionFactory::class)->driver(),
            $rootDir . '/database/migrations',
        ));
        $container->set(AssetManifest::class, static fn (): AssetManifest => new AssetManifest(
            $rootDir . '/public/assets/build/manifest.json',
        ));
        // The correlation identifier resolves per line rather than being
        // captured, because the session is minted partway through a request
        // and a value taken at any fixed moment would be wrong on one side of
        // it (`[20.13]`).
        $container->set(CorrelationContext::class, static fn (Container $c): CorrelationContext => new CorrelationContext(
            static fn (): ?string => $c->get(JourneyStore::class)->sessionUuid(),
        ));
        $container->set(OperatorLog::class, static fn (Container $c): OperatorLog => new OperatorLog(
            $rootDir . '/storage/logs/app.log',
            $c->get(CorrelationContext::class),
        ));
        $container->set(Instrumentation::class, static fn (Container $c): Instrumentation => new Instrumentation(
            new BoundaryTimer($c->get(OperatorLog::class)),
        ));
        $container->set(
            EmrClientFactory::class,
            static fn (Container $c): EmrClientFactory => new EmrClientFactory($c->get(Config::class), $rootDir),
        );
        $container->set(SessionGateway::class, static function (Container $c) use ($config): SessionGateway {
            if ($config->get('app.session.analytics') !== true) {
                return new NullSessionGateway();
            }

            return $c->get(Instrumentation::class)->sessionGateway(
                new EmrSessionGateway($c->get(EmrClientFactory::class), $c->get(OperatorLog::class)),
            );
        });
        $container->set(CartGateway::class, static function (Container $c) use ($config): CartGateway {
            if ($config->get('app.session.analytics') !== true) {
                return new NullCartGateway();
            }

            return $c->get(Instrumentation::class)->cartGateway(
                new EmrCartGateway($c->get(EmrClientFactory::class), $c->get(OperatorLog::class)),
            );
        });
        $container->set(CartMirror::class, static fn (Container $c): CartMirror => new CartMirror(
            $c->get(CartGateway::class),
            $c->get(OperatorLog::class),
            // The local audit trail (`[18.4]`). Written before the mirror and
            // outside its failure boundary, so an EMR outage leaves the
            // storefront's own record of the cart complete rather than blank.
            $c->get(EventRepository::class),
        ));
        $container->set(DefinitionCache::class, static fn (Container $c): DefinitionCache => new DefinitionCache(
            $rootDir . '/storage/cache/teleforms',
            (int) $c->get(Config::class)->get('intake.cache.ttl_seconds', 86400),
        ));
        $container->set(TeleformGateway::class, static function (Container $c) use ($config): TeleformGateway {
            if ($config->get('app.session.analytics') !== true) {
                return new NullTeleformGateway();
            }

            return $c->get(Instrumentation::class)->teleformGateway(
                new EmrTeleformGateway($c->get(EmrClientFactory::class), $c->get(OperatorLog::class)),
            );
        });
        $container->set(TeleformSource::class, static fn (Container $c): TeleformSource => new TeleformSource(
            $c->get(TeleformGateway::class),
            $c->get(DefinitionCache::class),
            $c->get(OperatorLog::class),
        ));
        $container->set(RuleEvaluator::class, static fn (Container $c): RuleEvaluator => new RuleEvaluator($c->get(OperatorLog::class)));
        $container->set(RecordMapper::class, static fn (Container $c): RecordMapper => new RecordMapper($c->get(OperatorLog::class)));
        $container->set(AnswerValidator::class, static fn (Container $c): AnswerValidator => new AnswerValidator($c->get(RuleEvaluator::class)));
        $container->set(Disqualification::class, static fn (Container $c): Disqualification => new Disqualification(
            $c->get(RuleEvaluator::class),
            $c->get(Config::class),
            $c->get(OperatorLog::class),
        ));
        // Wrapped rather than gated: the EMR half is switched off with the
        // analytics flag, but the local audit line is not analytics and is
        // written either way (`[18.1]`). The six questionnaire events reached
        // the EMR from the day the intake flow was built and landed nowhere
        // locally; the decorator is what closes that.
        $container->set(IntakeGateway::class, static function (Container $c) use ($config): IntakeGateway {
            $inner = $config->get('app.session.analytics') === true
                ? $c->get(Instrumentation::class)->intakeGateway(
                    new EmrIntakeGateway($c->get(EmrClientFactory::class), $c->get(OperatorLog::class)),
                )
                : new NullIntakeGateway();

            return new RecordingIntakeGateway($inner, $c->get(EventRepository::class));
        });
        $container->set(LeadGateway::class, static function (Container $c) use ($config): LeadGateway {
            if ($config->get('app.session.analytics') !== true) {
                return new NullLeadGateway();
            }

            return $c->get(Instrumentation::class)->leadGateway(
                new EmrLeadGateway($c->get(EmrClientFactory::class), $c->get(OperatorLog::class)),
            );
        });
        $container->set(LeadWriter::class, static fn (Container $c): LeadWriter => new LeadWriter(
            $c->get(LeadGateway::class),
            $c->get(RecordMapper::class),
            $c->get(OperatorLog::class),
        ));
        // Three attempts a minute is enough for a visitor filling in a name and
        // an email, and low enough that an accidental loop stops mattering.
        $container->set(RateLimiter::class, static fn (): RateLimiter => new RateLimiter(3, 60));
        $container->set(IntakeSession::class, static fn (Container $c): IntakeSession => new IntakeSession(
            $c->get(TeleformSource::class),
            $c->get(JourneyStore::class),
            $c->get(AnswerValidator::class),
            $c->get(Disqualification::class),
            $c->get(IntakeGateway::class),
            $c->get(LeadWriter::class),
            $c->get(RecordMapper::class),
            $c->get(OperatorLog::class),
        ));
        $container->set(IntakeController::class, static fn (Container $c): IntakeController => new IntakeController(
            $c->get(IntakeSession::class),
            $c->get(CartStore::class),
            $c->get(JourneyStore::class),
            $c->get(ProductCatalog::class),
            $c->get(FlowDefinition::class),
            $c->get(RuleEvaluator::class),
            $c->get(RateLimiter::class),
            $c->get(Config::class),
            $c->get(OperatorLog::class),
            $c->get(FunnelRouter::class),
        ));
        $container->set(NotEligibleController::class, static fn (Container $c): NotEligibleController => new NotEligibleController(
            $c->get(TeleformSource::class),
            $c->get(Disqualification::class),
            $c->get(CartStore::class),
            $c->get(ProductCatalog::class),
            $c->get(CartRules::class),
            $c->get(CartMirror::class),
            $c->get(JourneyStore::class),
            $c->get(FlowDefinition::class),
            $c->get(OperatorLog::class),
        ));
        $container->set(Twig::class, static function (Container $c) use ($rootDir, $config): Twig {
            $isProd = $config->get('app.env') === 'production';

            $twig = Twig::create($rootDir . '/theme/templates', [
                'cache' => $isProd ? $rootDir . '/storage/cache/twig' : false,
                'auto_reload' => !$isProd,
            ]);
            $twig->addExtension(new TwigExtensions($c->get(AssetManifest::class)));

            return $twig;
        });
        $trace = $config->get('app.env') === 'test';
        $container->set(SessionMiddleware::class, static fn (): SessionMiddleware => new SessionMiddleware(
            $rootDir . '/storage/sessions',
            $trace,
        ));
        $container->set(HealthController::class, static fn (Container $c): HealthController => new HealthController(
            $c->get(Config::class),
            $c->get(ConnectionFactory::class),
            $rootDir,
            // Both probes are closures so that an ordinary `/health/` hit
            // never builds an EMR client or a payment adapter, let alone calls
            // one. `emr:ping`'s own mechanism, not a second one.
            new Reachability(
                emr: static function () use ($c): bool {
                    $factory = $c->get(EmrClientFactory::class);
                    $factory->create()->channels()->details($factory->channelId());

                    return true;
                },
                provider: static fn (): bool => $c->get(PaymentAdapter::class)->ping()['ok'],
                cacheFile: $rootDir . '/storage/cache/health/reachability.json',
            ),
            $c->get(DefinitionCache::class),
        ));
        $container->set(
            CatalogProvider::class,
            static fn (Container $c): CatalogProvider => new CatalogProvider($c->get(Config::class)),
        );
        // CatalogProvider is final and cannot be subclassed, so this alias is
        // what lets a later test vary the catalog CartRules sees. Everything
        // that needs listedProducts()/categories() (not on the interface)
        // stays on the concrete CatalogProvider below.
        $container->set(ProductCatalog::class, static fn (Container $c): ProductCatalog => $c->get(CatalogProvider::class));
        $container->set(CartRules::class, static fn (Container $c): CartRules => new CartRules($c->get(ProductCatalog::class)));
        $container->set(
            ProductListController::class,
            static fn (Container $c): ProductListController => new ProductListController($c->get(CatalogProvider::class)),
        );
        $container->set(
            ProductDetailController::class,
            static fn (Container $c): ProductDetailController => new ProductDetailController($c->get(CatalogProvider::class)),
        );
        $container->set(
            TemplateGlobalsMiddleware::class,
            // The CartStore is a closure for the same reason StepGuardMiddleware's
            // pair below is: container resolution is deferred into the
            // middleware's own error boundary, so a failure building the store
            // degrades the drawer rather than the pipeline. Since
            // SessionRepository opens its connection lazily, that resolution no
            // longer touches the database at all — the boundary is now
            // defence-in-depth against a future collaborator that does.
            static fn (Container $c): TemplateGlobalsMiddleware => new TemplateGlobalsMiddleware(
                $c->get(Twig::class),
                $c->get(Config::class),
                $c->get(CatalogProvider::class),
                $c->get(FlowDefinition::class),
                $c->get(OperatorLog::class),
                static fn (): CartStore => $c->get(CartStore::class),
                $c->get(FunnelRouter::class),
                static fn (): JourneyStore => $c->get(JourneyStore::class),
                $trace,
            ),
        );
        $container->set(
            CartController::class,
            // The stores are injected directly rather than as closures: unlike
            // the two middlewares above, this one has no useful degraded mode
            // — a cart mutation with no cart to mutate is a lost order, not a
            // quieter page. It is safe to take them eagerly because
            // SessionRepository defers its connection, so nothing here touches
            // the database and a mutation posted during an outage still lands
            // in the PHP session (`[20.1]`).
            static fn (Container $c): CartController => new CartController(
                $c->get(CartRules::class),
                $c->get(CartStore::class),
                $c->get(CartMirror::class),
                $c->get(FunnelRouter::class),
                $c->get(FlowDefinition::class),
                $c->get(JourneyStore::class),
            ),
        );
        $container->set(
            CanonicalUrlMiddleware::class,
            static fn (): CanonicalUrlMiddleware => new CanonicalUrlMiddleware($trace),
        );
        // `Config::class` rather than the lexical `$config` for the same
        // reason the verification bindings resolve it from the container: a
        // test that varies `app.seo` has to be able to reach these, and a
        // closed-over value cannot be overridden.
        $container->set(MetaResolver::class, static fn (Container $c): MetaResolver => new MetaResolver($c->get(Config::class)));
        $container->set(RobotsPolicy::class, static fn (Container $c): RobotsPolicy => RobotsPolicy::fromConfig($c->get(Config::class)));
        $container->set(RetentionPolicy::class, static fn (Container $c): RetentionPolicy => RetentionPolicy::fromConfig($c->get(Config::class)));
        $container->set(
            SeoMiddleware::class,
            static fn (Container $c): SeoMiddleware => new SeoMiddleware(
                $c->get(Twig::class),
                $c->get(MetaResolver::class),
                $c->get(RobotsPolicy::class),
                $trace,
            ),
        );
        $container->set(SitemapController::class, static fn (Container $c): SitemapController => new SitemapController(
            $c->get(CatalogProvider::class),
            $c->get(MetaResolver::class),
            $c->get(RobotsPolicy::class),
        ));
        $container->set(RobotsController::class, static fn (Container $c): RobotsController => new RobotsController(
            $c->get(Config::class),
            $c->get(MetaResolver::class),
            $c->get(RobotsPolicy::class),
        ));
        $container->set(
            CsrfMiddleware::class,
            static fn (): CsrfMiddleware => new CsrfMiddleware($trace),
        );
        $container->set(SessionOptions::class, static fn (): SessionOptions => SessionOptions::fromConfig($config));
        // Both repositories receive the connection as a closure, so building
        // one never opens it: the cart lives in the PHP session and reaches
        // the journey store — and therefore these classes — on every request,
        // including the ones a database outage must not stop (`[20.1]`).
        // Resolution still goes through the container, so a test overriding
        // `\PDO::class` overrides what these open.
        $container->set(SessionRepository::class, static fn (Container $c): SessionRepository => new SessionRepository(
            static fn (): \PDO => $c->get(\PDO::class),
        ));
        $container->set(EventRepository::class, static fn (Container $c): EventRepository => new EventRepository(
            static fn (): \PDO => $c->get(\PDO::class),
        ));
        // A closure definition resolves to one instance per container (PHP-DI's
        // default), which is what makes this request-scoped: the resolver and
        // the save-back middleware below must see the same JourneyStore, or
        // the save-back would flush an empty state.
        $container->set(JourneyStore::class, static fn (Container $c): JourneyStore => new JourneyStore($c->get(SessionRepository::class)));
        // Same one-instance-per-container reasoning as JourneyStore above: a
        // controller and the templates that render its result must see the
        // same cart, or they will disagree about what is in it.
        $container->set(CartStore::class, static fn (Container $c): CartStore => new CartStore($c->get(JourneyStore::class)));
        $container->set(SessionResolver::class, static fn (Container $c): SessionResolver => new SessionResolver(
            $c->get(SessionGateway::class),
            $c->get(SessionRepository::class),
            $c->get(JourneyStore::class),
            $c->get(EventRepository::class),
            $c->get(OperatorLog::class),
            $c->get(SessionOptions::class),
        ));
        // Both session middlewares receive their collaborator as a closure so
        // the database connection behind it is created inside their own error
        // boundaries rather than while the container is building them.
        $container->set(AttributionMiddleware::class, static fn (Container $c): AttributionMiddleware => new AttributionMiddleware(
            static fn (): SessionResolver => $c->get(SessionResolver::class),
            $c->get(SessionOptions::class),
            $c->get(OperatorLog::class),
            $trace,
            // Published below, once the app exists to be asked. Resolved at
            // request time rather than here, so the order these two are
            // declared in does not matter.
            $c->has(self::ROUTE_EXISTS) ? $c->get(self::ROUTE_EXISTS) : null,
        ));
        $container->set(JourneyStateMiddleware::class, static fn (Container $c): JourneyStateMiddleware => new JourneyStateMiddleware(
            static fn (): JourneyStore => $c->get(JourneyStore::class),
            $c->get(OperatorLog::class),
            $trace,
        ));
        // The four funnel bindings below read their configuration out of the
        // container rather than out of the `$config` captured at the top of
        // this method, and the difference is the whole reason a test can say
        // anything about an enabled identity step.
        //
        // `$containerOverrides` is this project's documented test seam, and a
        // binding that closes over `$config` is outside it: a test could
        // substitute the gateway but not the setting that decides whether the
        // step exists, where it sits, or whether it blocks. The consequence
        // was measurable — `blocking => true` appeared in exactly one unit
        // test that never touched HTTP, and no request anywhere had ever been
        // walked through an enabled step. Resolving `Config::class` here costs
        // nothing when nobody overrides it (the container holds that same
        // object) and makes the configuration reachable when somebody does.
        //
        // Deliberately not widened past these four: the rest of `create()`
        // still uses `$config`, because a partial override that silently
        // changed a database path or an environment name would be a worse
        // seam than none. See {@see \AsterMD\Storefront\Tests\Bootstrap\ConfigSeamTest}.
        $container->set(
            FlowDefinition::class,
            static fn (Container $c): FlowDefinition => FlowDefinition::fromConfig($c->get(Config::class)),
        );
        // The identity gateway follows `verification.enabled`, not
        // `app.features.emr_verification`. The two govern different resources
        // that happen to share one EMR endpoint family -- the feature flag is
        // the email-deliverability and address checks at checkout -- and tying
        // them together would mean an operator could not have the address
        // check without also running identity checks that, as recorded,
        // currently refuse everybody.
        $container->set(IdentityGateway::class, static function (Container $c): IdentityGateway {
            if ((bool) $c->get(Config::class)->get('verification.enabled', false) !== true) {
                return new NullIdentityGateway();
            }

            return new EmrIdentityGateway($c->get(EmrClientFactory::class), $c->get(OperatorLog::class));
        });
        $container->set(VerificationStep::class, static fn (Container $c): VerificationStep => new VerificationStep(
            $c->get(IdentityGateway::class),
            $c->get(TeleformSource::class),
            $c->get(ProductCatalog::class),
            $c->get(OperatorLog::class),
            $c->get(Config::class),
        ));
        $container->set(VerifyController::class, static fn (Container $c): VerifyController => new VerifyController(
            $c->get(VerificationStep::class),
            $c->get(CartStore::class),
            $c->get(JourneyStore::class),
            $c->get(FlowDefinition::class),
            $c->get(FunnelRouter::class),
            $c->get(StepPreconditions::class),
            $c->get(OperatorLog::class),
        ));
        $container->set(
            StepPreconditions::class,
            static fn (Container $c): StepPreconditions => new StepPreconditions(
                $c->get(ProductCatalog::class),
                (array) $c->get(Config::class)->get('verification', []),
            ),
        );
        // Resolved through the ProductCatalog::class alias, not CatalogProvider
        // directly, so a test that overrides the alias to vary the catalog
        // (see the comment above that alias's own definition) changes what
        // this router sees too.
        $container->set(FunnelRouter::class, static fn (Container $c): FunnelRouter => new FunnelRouter(
            $c->get(ProductCatalog::class),
            (array) $c->get(Config::class)->get('verification', []),
        ));
        // The cart/journey pair arrives as a closure for the same reason
        // AttributionMiddleware's and JourneyStateMiddleware's collaborators
        // do: resolution happens inside the guard's own error boundary rather
        // than while the pipeline is being assembled. Since SessionRepository
        // opens its connection lazily, building the pair no longer reaches the
        // database — the guard now evaluates a real cart during an outage
        // instead of standing down, which is the better half of `[20.1]`.
        $container->set(StepGuardMiddleware::class, static fn (Container $c): StepGuardMiddleware => new StepGuardMiddleware(
            static fn (): array => [$c->get(CartStore::class), $c->get(JourneyStore::class)],
            $c->get(FlowDefinition::class),
            $c->get(FunnelRouter::class),
            $c->get(StepPreconditions::class),
            $c->get(OperatorLog::class),
            $trace,
        ));
        // The journey arrives as a closure for the same reason the guard's
        // stores do: this middleware runs before the journey exists, and
        // resolving one while the pipeline is assembled would put a database
        // connection outside every error boundary.
        $container->set(RetiredSessionMiddleware::class, static fn (Container $c): RetiredSessionMiddleware => new RetiredSessionMiddleware(
            static fn (): JourneyStore => $c->get(JourneyStore::class),
            $c->get(FlowDefinition::class),
            $c->get(SessionOptions::class),
            $trace,
        ));

        // Checkout and payment. Every binding here is a closure, for a
        // reason a live outage taught: a service resolved while the container is
        // being built escapes the error boundary, and one that opens a
        // database connection on construction turns an analytics outage into a
        // storefront that cannot take money (`[20.1]`).
        $container->set(VerificationGateway::class, static function (Container $c) use ($config): VerificationGateway {
            // Off by default, and the whole resource is refused for the
            // credential this ships with. A check that can never answer must
            // not be the one deciding whether an order proceeds.
            if ($config->get('app.features.emr_verification') !== true) {
                return new NullVerificationGateway();
            }

            return new EmrVerificationGateway($c->get(EmrClientFactory::class), $c->get(OperatorLog::class));
        });
        $container->set(OrderRepository::class, static fn (Container $c): OrderRepository => new OrderRepository(
            static fn (): \PDO => $c->get(\PDO::class),
        ));
        $container->set(CheckoutAttemptRepository::class, static fn (Container $c): CheckoutAttemptRepository => new CheckoutAttemptRepository(
            static fn (): \PDO => $c->get(\PDO::class),
            $c->get(ConnectionFactory::class)->driver(),
        ));
        $container->set(RateLimitRepository::class, static fn (Container $c): RateLimitRepository => new RateLimitRepository(
            static fn (): \PDO => $c->get(\PDO::class),
            $c->get(ConnectionFactory::class)->driver(),
        ));
        $container->set(DatabaseRateLimiter::class, static fn (Container $c): DatabaseRateLimiter => new DatabaseRateLimiter(
            $c->get(RateLimitRepository::class),
            (array) $c->get(Config::class)->get('payment.rate_limits', []),
            $c->get(OperatorLog::class),
        ));
        $container->set(AdapterRegistry::class, static function (Container $c) use ($config, $rootDir): AdapterRegistry {
            $registry = new AdapterRegistry($c->get(OperatorLog::class));

            // Registered as a factory rather than an instance: a deployment
            // whose channel carries no payment processor must still boot, and
            // the registry answers with a configuration-error decline instead
            // of a container that cannot be built.
            $registry->register('vrio', static fn (): PaymentAdapter => new VrioAdapter(
                VrioCredentials::fromChannelConfig((array) $config->get('channel.generated.payment_processor.config', [])),
                // The wire log decorates the provider's own default transport
                // rather than replacing it, so behaviour is identical whether
                // it is on or off — a debugging switch that changed what the
                // provider was sent would be worse than no switch.
                new VrioApiFactory(
                    // Two independent decisions about one slot, composed
                    // rather than raced.
                    //
                    // The inner client is what actually reaches the provider,
                    // and under the test environment it is one that refuses to
                    // (see {@see \AsterMD\Storefront\Payment\RefusingTransport}):
                    // the adapter is chosen from synced channel data rather
                    // than from the environment (`[14.3]`), so unlike every EMR
                    // port there is no Null implementation standing between a
                    // test and the live provider.
                    //
                    // The wire log then *wraps* whatever that is. Composing
                    // them keeps both properties provable at once — the switch
                    // demonstrably attaches a transcript writer, and the suite
                    // still cannot place an order — where choosing one or the
                    // other would have made the switch untestable in the only
                    // environment that tests it.
                    (static function () use ($config, $rootDir): ?HttpClientInterface {
                        $inner = $config->get('app.env') === 'test' ? new RefusingTransport() : null;

                        if ($config->get('app.debug.wire_log') !== true) {
                            return $inner;
                        }

                        return new VrioWireLog($inner ?? new CurlClient(), $rootDir . '/storage/logs/vrio-wire.log');
                    })(),
                ),
                $c->get(OperatorLog::class),
                (int) $config->get('payment.shipping_profile_id', 1),
            ));

            return $registry;
        });
        // The provider is chosen from synced channel data, never named by a
        // code path (`[14.3]`); the config key is an operator override for a
        // deployment that has to pin one.
        $container->set(PaymentAdapter::class, static fn (Container $c): PaymentAdapter => $c->get(Instrumentation::class)->paymentAdapter(
            $c->get(AdapterRegistry::class)->for(
                (string) ($c->get(Config::class)->get('payment.adapter')
                    ?? $c->get(Config::class)->get('channel.generated.payment_processor.provider_category', '')),
            ),
        ));
        $container->set(Consents::class, static fn (Container $c): Consents => Consents::fromConfig(
            (array) $c->get(Config::class)->get('consent', []),
        ));
        $container->set(OrderBumps::class, static fn (Container $c): OrderBumps => OrderBumps::fromConfig(
            (array) $c->get(Config::class)->get('cross-sells', []),
            $c->get(ProductCatalog::class),
            $c->get(OperatorLog::class),
        ));
        $container->set(OrderRecorder::class, static fn (Container $c): OrderRecorder => new DatabaseOrderRecorder(
            $c->get(OrderRepository::class),
            $c->get(OperatorLog::class),
        ));
        $container->set(PostChargeGuard::class, static fn (Container $c): PostChargeGuard => new PostChargeGuard(
            $c->get(OperatorLog::class),
        ));
        $container->set(Upsells::class, static fn (Container $c): Upsells => Upsells::fromConfig(
            (array) $c->get(Config::class)->get('upsells', []),
            $c->get(ProductCatalog::class),
            $c->get(OperatorLog::class),
        ));
        $container->set(UpsellQueue::class, static fn (Container $c): UpsellQueue => new UpsellQueue(
            $c->get(Upsells::class),
            $c->get(OperatorLog::class),
        ));
        // Bound whatever the analytics flag says, unlike every EMR gateway
        // above it, because this writes two places and only one of them is
        // analytics: `[18.1]`'s local audit trail is the storefront's own
        // record and must survive analytics being switched off. The flag is
        // handed to the reporter instead, which skips the EMR half.
        $container->set(CheckoutEventReporter::class, static fn (Container $c): CheckoutEventReporter => $c->get(Instrumentation::class)->checkoutEventReporter(new EmrCheckoutEventReporter(
            $c->get(EmrClientFactory::class),
            $c->get(EventRepository::class),
            $c->get(OrderRepository::class),
            $c->get(OperatorLog::class),
            // Resolved on use rather than now: the journey is request-scoped
            // and does not exist while the container is being built.
            static fn (): ?string => $c->get(JourneyStore::class)->state()?->attribution?->get('utm_source'),
            (string) $c->get(Config::class)->get('payment.currency', 'USD'),
            $config->get('app.session.analytics') === true,
        )));
        $container->set(CheckoutService::class, static fn (Container $c): CheckoutService => new CheckoutService(
            $c->get(CartStore::class),
            $c->get(JourneyStore::class),
            $c->get(CartRules::class),
            $c->get(ProductCatalog::class),
            $c->get(PaymentAdapter::class),
            $c->get(Consents::class),
            $c->get(OrderBumps::class),
            $c->get(VerificationGateway::class),
            $c->get(CheckoutAttemptRepository::class),
            $c->get(DatabaseRateLimiter::class),
            $c->get(OrderRecorder::class),
            $c->get(CheckoutEventReporter::class),
            $c->get(TeleformSource::class),
            $c->get(FlowDefinition::class),
            $c->get(Config::class),
            $c->get(OperatorLog::class),
            $c->get(PostChargeGuard::class),
            $c->get(Upsells::class),
        ));
        $container->set(CheckoutController::class, static fn (Container $c): CheckoutController => new CheckoutController(
            $c->get(CheckoutService::class),
            $c->get(CartStore::class),
        ));
        // Post-purchase upsells and the completion step. The upsell charge
        // reuses the checkout's attempt table, rate limiter and post-charge
        // discipline rather than growing its own, so everything here is wired
        // from bindings that already existed.
        $container->set(UpsellService::class, static fn (Container $c): UpsellService => new UpsellService(
            $c->get(JourneyStore::class),
            $c->get(UpsellQueue::class),
            $c->get(PaymentAdapter::class),
            $c->get(CheckoutAttemptRepository::class),
            $c->get(DatabaseRateLimiter::class),
            $c->get(OrderRecorder::class),
            $c->get(CheckoutEventReporter::class),
            $c->get(PostChargeGuard::class),
            $c->get(FlowDefinition::class),
            $c->get(Config::class),
            $c->get(OperatorLog::class),
        ));
        $container->set(UpsellController::class, static fn (Container $c): UpsellController => new UpsellController(
            $c->get(UpsellService::class),
            $c->get(CartStore::class),
            $c->get(FlowDefinition::class),
        ));
        $container->set(Completion::class, static fn (Container $c): Completion => new Completion(
            $c->get(JourneyStore::class),
            $c->get(OrderRepository::class),
            $c->get(CheckoutEventReporter::class),
            $c->get(PostChargeGuard::class),
            $c->get(OperatorLog::class),
            (string) $c->get(Config::class)->get('payment.currency', 'USD'),
        ));
        $container->set(ReceiptController::class, static fn (Container $c): ReceiptController => new ReceiptController(
            $c->get(Completion::class),
        ));

        foreach ($containerOverrides as $id => $value) {
            $container->set($id, $value);
        }

        SlimAppFactory::setContainer($container);
        $app = SlimAppFactory::create();

        // Whether a path resolves to one of this app's routes, answered
        // without the routing middleware having run. AttributionMiddleware
        // needs it to avoid minting an EMR session for a 404, and it sits
        // outside routing in a stack whose order is load-bearing.
        $container->set(self::ROUTE_EXISTS, static function () use ($app): \Closure {
            $resolver = $app->getRouteResolver();

            return static function (ServerRequestInterface $request) use ($resolver): bool {
                try {
                    return $resolver->computeRoutingResults(
                        $request->getUri()->getPath(),
                        $request->getMethod(),
                    )->getRouteStatus() === Dispatcher::FOUND;
                } catch (\Throwable) {
                    // An unanswerable question is not evidence of a missing
                    // page. Falling back to "yes" keeps a resolver failure
                    // costing a spurious session rather than silently
                    // switching attribution off for the whole site.
                    return true;
                }
            };
        });

        $app->addRoutingMiddleware();
        // Registered lazily on purpose: see {@see LazyMiddleware}. Building
        // the pipeline here would create the database connection before the
        // error middleware below exists.
        foreach (array_reverse(self::MIDDLEWARE_ORDER) as $middlewareClass) {
            $app->add(new LazyMiddleware(
                static fn (): MiddlewareInterface => $container->get($middlewareClass),
            ));
        }
        $app->add(TwigMiddleware::create($app, $container->get(Twig::class)));

        $errorMiddleware = $app->addErrorMiddleware(
            displayErrorDetails: $config->get('app.env') !== 'production',
            logErrors: true,
            logErrorDetails: true,
        );
        $twig = $container->get(Twig::class);

        // `[24.2]` asks for the indexing directive twice over, as a response
        // header and as a meta tag, and an error response is not an exception
        // to that: it is served at any URL at all, including one a crawler
        // followed from somewhere.
        //
        // The error boundary is registered outside {@see self::MIDDLEWARE_ORDER}
        // — it has to wrap the pipeline to catch what the pipeline throws — so
        // a response it renders never passes back out through
        // {@see SeoMiddleware} and would carry the meta tag alone. Rather than
        // move the boundary inside the pipeline, which would change which
        // throws it can still catch, every error response is created with the
        // header already on it. The value is {@see RobotsPolicy}'s own
        // constant, which is what the error templates render their tag from,
        // so the two halves cannot disagree.
        $errorResponse = static fn (int $status): ResponseInterface => $app->getResponseFactory()
            ->createResponse($status)
            ->withHeader('X-Robots-Tag', RobotsPolicy::REFUSED);

        $errorMiddleware->setErrorHandler(
            \Slim\Exception\HttpNotFoundException::class,
            function (ServerRequestInterface $request) use ($twig, $errorResponse) {
                return $twig->render($errorResponse(404), 'pages/error-404.twig');
            },
        );
        $errorMiddleware->setDefaultErrorHandler(
            function (ServerRequestInterface $request, \Throwable $e) use ($twig, $container, $errorResponse) {
                if ($e instanceof \Slim\Exception\HttpSpecializedException) {
                    if ($e instanceof \Slim\Exception\HttpNotFoundException) {
                        return $twig->render($errorResponse(404), 'pages/error-404.twig');
                    }

                    // Client errors (405, 400, 403, ...): themed, but not logged —
                    // these aren't crashes, just malformed/disallowed requests.
                    $response = $errorResponse($e->getCode());

                    return $twig->render($response, 'pages/error-http.twig', [
                        'status' => $e->getCode(),
                        'message' => $e->getMessage(),
                    ]);
                }

                $requestId = bin2hex(random_bytes(8));
                // Path only, never the full URI: query strings carry tracking
                // payloads and resume identifiers, and key-based redaction
                // cannot see inside a URI string (`[20.6]`).
                $container->get(OperatorLog::class)->error('http.unhandled_exception', [
                    'request_id' => $requestId,
                    'method' => $request->getMethod(),
                    'path' => $request->getUri()->getPath(),
                    'session' => $request->getAttribute('session_uuid'),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile() . ':' . $e->getLine(),
                ]);

                return $twig->render($errorResponse(500), 'pages/error-500.twig', ['request_id' => $requestId]);
            },
        );

        $routes = require $rootDir . '/config/routes.php';
        $routes($app);

        return $app;
    }
}
