<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Entity\Ticket;
use App\Enum\TicketStatus;
use App\Ticket\TicketLifecycleService;
use App\Ticket\TicketStatusWorkflow;

$workflow = new TicketStatusWorkflow();
$lifecycle = new TicketLifecycleService($workflow);

$ticket = new Ticket();
$firstTransition = $lifecycle->transition($ticket, TicketStatus::IN_PROGRESS, reason: 'Foundation smoke test');

$invalidTransitionRejected = false;
$invalidTicket = new Ticket();
try {
    $lifecycle->transition($invalidTicket, TicketStatus::CLOSED);
} catch (DomainException) {
    $invalidTransitionRejected = true;
}

$lifecycle->transition($ticket, TicketStatus::WAITING_FOR_MEMBER);
$lifecycle->transition($ticket, TicketStatus::IN_PROGRESS);

$resolvedAt = new DateTimeImmutable('2026-09-15 10:30:00');
$lifecycle->transition($ticket, TicketStatus::RESOLVED, occurredAt: $resolvedAt);
$resolvedTimestampWasApplied = $ticket->getResolvedAt() === $resolvedAt;

$lifecycle->transition($ticket, TicketStatus::REOPENED);
$reopenClearedResolution = $ticket->getResolvedAt() === null && $ticket->getClosedAt() === null;

$checks = [
    'open_to_in_progress' => $workflow->canTransition(TicketStatus::OPEN, TicketStatus::IN_PROGRESS),
    'in_progress_to_waiting' => $workflow->canTransition(TicketStatus::IN_PROGRESS, TicketStatus::WAITING_FOR_MEMBER),
    'waiting_to_in_progress' => $workflow->canTransition(TicketStatus::WAITING_FOR_MEMBER, TicketStatus::IN_PROGRESS),
    'resolved_to_reopened' => $workflow->canTransition(TicketStatus::RESOLVED, TicketStatus::REOPENED),
    'closed_can_reopen' => $workflow->canTransition(TicketStatus::CLOSED, TicketStatus::REOPENED),
    'open_cannot_skip_to_closed' => !$workflow->canTransition(TicketStatus::OPEN, TicketStatus::CLOSED),
    'service_transition_changes_ticket_status' => $ticket->getStatus() === TicketStatus::REOPENED,
    'service_transition_records_from_status' => $firstTransition->getFromStatus() === TicketStatus::OPEN,
    'service_transition_records_to_status' => $firstTransition->getToStatus() === TicketStatus::IN_PROGRESS,
    'invalid_service_transition_rejected' => $invalidTransitionRejected,
    'resolved_sets_timestamp' => $resolvedTimestampWasApplied,
    'reopened_clears_resolution_timestamp' => $reopenClearedResolution,
    'status_history_is_recorded' => $ticket->getStatusHistory()->count() === 5,
];

$failed = array_keys(array_filter($checks, static fn (bool $passed): bool => !$passed));

if ($failed !== []) {
    fwrite(STDERR, 'Foundation checks failed: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo 'Foundation checks passed.' . PHP_EOL;
