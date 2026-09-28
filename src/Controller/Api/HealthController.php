<?php

declare(strict_types=1);

namespace App\Controller\Api;

use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class HealthController extends AbstractController
{
    #[Route('/health', name: 'health', methods: ['GET'])]
    #[Route('/health/', name: 'health_slash', methods: ['GET'])]
    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    public function __invoke(ManagerRegistry $doctrine): JsonResponse
    {
        $database = 'ok';
        $dbError = null;

        try {
            $connection = $doctrine->getConnection();
            $connection->executeQuery('SELECT 1')->fetchOne();
        } catch (\Throwable $e) {
            $database = 'unavailable';
            $dbError = $e->getMessage();
        }

        return $this->json([
            'service' => 'mudi-sacco-support',
            'status' => $database === 'ok' ? 'ok' : 'degraded',
            'checks' => [
                'database' => $database,
                'database_error' => $dbError,
            ],
            'timestamp' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ], 200);
    }
}
