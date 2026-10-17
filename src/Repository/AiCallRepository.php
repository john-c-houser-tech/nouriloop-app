<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AiCall;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AiCall>
 */
class AiCallRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AiCall::class);
    }

    /**
     * Totals per feature, provider, model and payer (NouriLoop's key or the user's own)
     * for calls in the half-open server-frame window [$from, $to), biggest estimated
     * cost first. `cost_micros` sums only the
     * rows that have an estimate; `unpriced_calls` counts the ones that don't, so a
     * total is never mistaken for complete.
     *
     * @return list<array{feature: string, provider: string, model: string, user_key: bool, calls: int,
     *     users: int, input_tokens: int, output_tokens: int, cost_micros: int, unpriced_calls: int}>
     */
    public function totals(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT feature, provider, model, user_key,
                    COUNT(*) AS calls,
                    COUNT(DISTINCT user_id) AS users,
                    SUM(input_tokens) AS input_tokens,
                    SUM(output_tokens) AS output_tokens,
                    COALESCE(SUM(cost_micros), 0) AS cost_micros,
                    SUM(cost_micros IS NULL) AS unpriced_calls
             FROM ai_call
             WHERE occurred_at >= :from AND occurred_at < :to
             GROUP BY feature, provider, model, user_key
             ORDER BY user_key, cost_micros DESC, calls DESC, feature, model',
            ['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')],
        );

        return array_map(static fn(array $r): array => [
            'feature' => (string) $r['feature'],
            'provider' => (string) $r['provider'],
            'model' => (string) $r['model'],
            'user_key' => (bool) $r['user_key'],
            'calls' => (int) $r['calls'],
            'users' => (int) $r['users'],
            'input_tokens' => (int) $r['input_tokens'],
            'output_tokens' => (int) $r['output_tokens'],
            'cost_micros' => (int) $r['cost_micros'],
            'unpriced_calls' => (int) $r['unpriced_calls'],
        ], $rows);
    }
}
