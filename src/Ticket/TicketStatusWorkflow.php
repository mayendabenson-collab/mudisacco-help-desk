<?php

declare(strict_types=1);

namespace App\Ticket;

use App\Enum\TicketStatus;
use DomainException;

class TicketStatusWorkflow
{
    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        'OPEN' => ['IN_PROGRESS'],
        'IN_PROGRESS' => ['WAITING_FOR_MEMBER', 'RESOLVED', 'CLOSED'],
        'WAITING_FOR_MEMBER' => ['IN_PROGRESS', 'CLOSED'],
        'RESOLVED' => ['CLOSED', 'REOPENED'],
        'REOPENED' => ['IN_PROGRESS', 'CLOSED'],
        'CLOSED' => ['REOPENED'],
    ];

    public function canTransition(TicketStatus $from, TicketStatus $to): bool
    {
        return in_array($to->value, self::TRANSITIONS[$from->value] ?? [], true);
    }

    public function assertTransition(TicketStatus $from, TicketStatus $to): void
    {
        if (!$this->canTransition($from, $to)) {
            throw new DomainException(sprintf('Ticket status cannot transition from %s to %s.', $from->value, $to->value));
        }
    }

    /** @return list<TicketStatus> */
    public function nextStatuses(TicketStatus $from): array
    {
        return array_map(static fn (string $status): TicketStatus => TicketStatus::from($status), self::TRANSITIONS[$from->value] ?? []);
    }

    /** @return array<string, list<string>> */
    public function transitionMap(): array
    {
        return self::TRANSITIONS;
    }
}
