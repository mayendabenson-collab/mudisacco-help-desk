<?php

declare(strict_types=1);

namespace App\Ticket;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

class TicketNumberGenerator
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function nextReference(?\DateTimeImmutable $date = null): string
    {
        $date ??= new \DateTimeImmutable();
        $dateKey = $date->format('Ymd');
        $prefix = 'MUD-' . $dateKey . '-';
        $connection = $this->entityManager->getConnection();

        $sequence = null;

        try {
            $isPg = $connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform;

            if ($isPg) {
                // In PostgreSQL, execute an atomic upsert that increments the counter and returns the new value.
                // This avoids aborted transactions caused by duplicate key exceptions on INSERT.
                $sequence = (int) $connection->fetchOne(
                    'INSERT INTO ticket_counters (date_key, next_number, updated_at)
                     VALUES (:date_key, 1, CURRENT_TIMESTAMP)
                     ON CONFLICT (date_key)
                     DO UPDATE SET next_number = ticket_counters.next_number + 1, updated_at = CURRENT_TIMESTAMP
                     RETURNING next_number',
                    ['date_key' => $dateKey]
                );
            } else {
                // SQLite (local dev & testing)
                $sequence = $connection->transactional(function (Connection $conn) use ($dateKey): int {
                    $conn->executeStatement(
                        'INSERT INTO ticket_counters (date_key, next_number, updated_at)
                         VALUES (:date_key, 1, CURRENT_TIMESTAMP)
                         ON CONFLICT(date_key)
                         DO UPDATE SET next_number = ticket_counters.next_number + 1, updated_at = CURRENT_TIMESTAMP',
                        ['date_key' => $dateKey]
                    );

                    return (int) $conn->fetchOne(
                        'SELECT next_number FROM ticket_counters WHERE date_key = :date_key',
                        ['date_key' => $dateKey]
                    );
                });
            }
        } catch (\Throwable) {
            // Fallback: If ticket_counters table is unavailable or error occurs, calculate next count from tickets table directly
        }

        if ($sequence === null || $sequence <= 0) {
            try {
                $count = (int) $connection->fetchOne(
                    'SELECT COUNT(id) FROM tickets WHERE reference LIKE :likePattern',
                    ['likePattern' => $prefix . '%']
                );
                $sequence = $count + 1;
            } catch (\Throwable) {
                $sequence = random_int(1, 999999);
            }
        }

        return $prefix . str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }
}