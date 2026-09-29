<?php

declare(strict_types=1);

namespace App\Controller\Api;

use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Psr\Log\LoggerInterface;

class HealthController extends AbstractController
{
    #[Route('/health', name: 'health', methods: ['GET'])]
    #[Route('/health/', name: 'health_slash', methods: ['GET'])]
    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    public function __invoke(ManagerRegistry $doctrine, LoggerInterface $logger): JsonResponse
    {
        $database = 'ok';

        try {
            $connection = $doctrine->getConnection();
            $connection->executeQuery('SELECT 1')->fetchOne();
        } catch (\Throwable $e) {
            $database = 'unavailable';
            $logger->error('Health check database connection failed.', ['exception' => $e]);
        }

        return $this->json([
            'service' => 'mudi-sacco-support',
            'status' => $database === 'ok' ? 'ok' : 'degraded',
            'checks' => [
                'database' => $database,
            ],
            'timestamp' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ], 200);
    }
}
