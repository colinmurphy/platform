<?php declare(strict_types=1);

namespace Shopware\Core\System\Currency\SalesChannel;

use OpenApi\Annotations as OA;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Adapter\Cache\AbstractCacheTracer;
use Shopware\Core\Framework\Adapter\Cache\CacheCompressor;
use Shopware\Core\Framework\DataAbstractionLayer\Cache\EntityCacheKeyGenerator;
use Shopware\Core\Framework\DataAbstractionLayer\FieldSerializer\JsonFieldSerializer;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Routing\Annotation\Entity;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\Framework\Routing\Annotation\Since;
use Shopware\Core\System\Currency\Event\CurrencyRouteCacheKeyEvent;
use Shopware\Core\System\Currency\Event\CurrencyRouteCacheTagsEvent;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\StoreApiResponse;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * @RouteScope(scopes={"store-api"})
 */
class CachedCurrencyRoute extends AbstractCurrencyRoute
{
    public const ALL_TAG = 'currency-route';

    private AbstractCurrencyRoute $decorated;

    private TagAwareAdapterInterface $cache;

    private EntityCacheKeyGenerator $generator;

    /**
     * @var AbstractCacheTracer<CurrencyRouteResponse>
     */
    private AbstractCacheTracer $tracer;

    private array $states;

    private EventDispatcherInterface $dispatcher;

    private LoggerInterface $logger;

    /**
     * @param AbstractCacheTracer<CurrencyRouteResponse> $tracer
     */
    public function __construct(
        AbstractCurrencyRoute $decorated,
        TagAwareAdapterInterface $cache,
        EntityCacheKeyGenerator $generator,
        AbstractCacheTracer $tracer,
        EventDispatcherInterface $dispatcher,
        array $states,
        LoggerInterface $logger
    ) {
        $this->decorated = $decorated;
        $this->cache = $cache;
        $this->generator = $generator;
        $this->tracer = $tracer;
        $this->states = $states;
        $this->dispatcher = $dispatcher;
        $this->logger = $logger;
    }

    public static function buildName(string $salesChannelId): string
    {
        return 'currency-route-' . $salesChannelId;
    }

    public function getDecorated(): AbstractCurrencyRoute
    {
        return $this->decorated;
    }

    /**
     * @Since("6.2.0.0")
     * @Entity("currency")
     * @OA\Post(
     *      path="/currency",
     *      summary="Fetch currencies",
     *      description="Perform a filtered search for currencies.",
     *      operationId="readCurrency",
     *      tags={"Store API", "System & Context"},
     *      @OA\Parameter(name="Api-Basic-Parameters"),
     *      @OA\Response(
     *          response="200",
     *          description="Entity search result containing currencies.",
     *          @OA\JsonContent(
     *              type="object",
     *              allOf={
     *                  @OA\Schema(ref="#/components/schemas/EntitySearchResult"),
     *                  @OA\Schema(type="object",
     *                      @OA\Property(
     *                          type="array",
     *                          property="elements",
     *                          @OA\Items(ref="#/components/schemas/Currency")
     *                      )
     *                  )
     *              }
     *          )
     *     )
     * )
     * @Route("/store-api/currency", name="store-api.currency", methods={"GET", "POST"})
     */
    public function load(Request $request, SalesChannelContext $context, Criteria $criteria): CurrencyRouteResponse
    {
        if ($context->hasState(...$this->states)) {
            $this->logger->info('cache-miss: ' . self::buildName($context->getSalesChannelId()));

            return $this->getDecorated()->load($request, $context, $criteria);
        }

        $item = $this->cache->getItem(
            $this->generateKey($request, $context, $criteria)
        );

        try {
            if ($item->isHit() && $item->get()) {
                $this->logger->info('cache-hit: ' . self::buildName($context->getSalesChannelId()));

                return CacheCompressor::uncompress($item);
            }
        } catch (\Throwable $e) {
            $this->logger->error($e->getMessage());
        }

        $this->logger->info('cache-miss: ' . self::buildName($context->getSalesChannelId()));

        $name = self::buildName($context->getSalesChannelId());
        $response = $this->tracer->trace($name, function () use ($request, $context, $criteria) {
            return $this->getDecorated()->load($request, $context, $criteria);
        });

        $item = CacheCompressor::compress($item, $response);

        $item->tag($this->generateTags($request, $response, $context, $criteria));

        $this->cache->save($item);

        return $response;
    }

    private function generateKey(Request $request, SalesChannelContext $context, Criteria $criteria): string
    {
        $parts = [
            self::buildName($context->getSalesChannelId()),
            $this->generator->getCriteriaHash($criteria),
            $this->generator->getSalesChannelContextHash($context),
        ];

        $event = new CurrencyRouteCacheKeyEvent($parts, $request, $context, $criteria);
        $this->dispatcher->dispatch($event);

        return md5(JsonFieldSerializer::encodeJson($event->getParts()));
    }

    private function generateTags(Request $request, StoreApiResponse $response, SalesChannelContext $context, Criteria $criteria): array
    {
        $tags = array_merge(
            $this->tracer->get(self::buildName($context->getSalesChannelId())),
            [self::buildName($context->getSalesChannelId()), self::ALL_TAG],
        );

        $event = new CurrencyRouteCacheTagsEvent($tags, $request, $response, $context, $criteria);
        $this->dispatcher->dispatch($event);

        return array_unique(array_filter($event->getTags()));
    }
}
