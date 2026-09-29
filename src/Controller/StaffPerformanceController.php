<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Ticket;
use App\Entity\TicketAssignment;
use App\Entity\User;
use App\Security\SystemRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

class StaffPerformanceController extends AbstractController
{
    #[Route('/reports/staff-performance', name: 'staff_performance_report', methods: ['GET'])]
    public function __invoke(EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted(SystemRole::ADMIN->value);

        return $this->render('reports/staff_performance.html.twig', $this->buildReport($entityManager));
    }

    #[Route('/reports/staff-performance.csv', name: 'staff_performance_report_csv', methods: ['GET'])]
    public function download(EntityManagerInterface $entityManager): StreamedResponse
    {
        $this->denyAccessUnlessGranted(SystemRole::ADMIN->value);
        $report = $this->buildReport($entityManager);

        $response = new StreamedResponse(function () use ($report): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, ['Ticket', 'Department', 'Member', 'Assignment type', 'Assigned to', 'Assigned by', 'Assigned at', 'Closed at', 'Time held', 'Closing assignment']);

            foreach ($report['ticketRows'] as $row) {
                fputcsv($output, [
                    $row['ticket']->getReference(),
                    $row['ticket']->getDepartment()->getName(),
                    $row['ticket']->getMember()->getDisplayName(),
                    $row['assignmentType'],
                    $row['staffName'],
                    $row['assignedBy'],
                    $row['assignedAt']->format(DATE_ATOM),
                    $row['ticket']->getClosedAt()?->format(DATE_ATOM),
                    $row['duration'],
                    $row['isClosingAssignment'] ? 'Yes' : 'No',
                ]);
            }

            fclose($output);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="staff-performance-report.csv"');

        return $response;
    }

    /** @return array{summaries: array<string, array<string, mixed>>, ticketRows: list<array<string, mixed>>} */
    private function buildReport(EntityManagerInterface $entityManager): array
    {
        $assignments = $entityManager->createQueryBuilder()
            ->select('assignment', 'ticket', 'assignedTo', 'assignedBy', 'department', 'member')
            ->from(TicketAssignment::class, 'assignment')
            ->join('assignment.ticket', 'ticket')
            ->leftJoin('assignment.assignedTo', 'assignedTo')
            ->leftJoin('assignment.assignedBy', 'assignedBy')
            ->join('ticket.department', 'department')
            ->join('ticket.member', 'member')
            ->where('ticket.closedAt IS NOT NULL')
            ->orderBy('assignment.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        $byTicket = [];
        foreach ($assignments as $assignment) {
            if ($assignment instanceof TicketAssignment) {
                $byTicket[$assignment->getTicket()->getId()->toRfc4122()][] = $assignment;
            }
        }

        $summaries = [];
        $ticketRows = [];
        foreach ($byTicket as $ticketAssignments) {
            $ticket = $ticketAssignments[0]->getTicket();
            $closedAt = $ticket->getClosedAt();
            if ($closedAt === null) {
                continue;
            }

            foreach ($ticketAssignments as $index => $assignment) {
                $nextAssignment = $ticketAssignments[$index + 1] ?? null;
                $endAt = $nextAssignment instanceof TicketAssignment ? $nextAssignment->getCreatedAt() : $closedAt;
                $seconds = max(0, $endAt->getTimestamp() - $assignment->getCreatedAt()->getTimestamp());
                $assignedTo = $assignment->getAssignedTo();
                $staffKey = $assignedTo?->getId()?->toRfc4122() ?? 'unassigned';
                $staffName = $assignedTo?->getFullName() ?: 'Unassigned';
                $isClosingAssignment = $nextAssignment === null;

                $ticketRows[] = [
                    'ticket' => $ticket,
                    'staffName' => $staffName,
                    'assignedBy' => $assignment->getAssignedBy()?->getFullName() ?: 'Automatic system assignment',
                    'assignmentType' => $assignment->getAssignedBy() instanceof User ? 'Manual' : 'Automatic',
                    'assignedAt' => $assignment->getCreatedAt(),
                    'duration' => $this->formatDuration($seconds),
                    'isClosingAssignment' => $isClosingAssignment,
                ];

                if (!$isClosingAssignment) {
                    continue;
                }

                if (!isset($summaries[$staffKey])) {
                    $summaries[$staffKey] = ['name' => $staffName, 'closed' => 0, 'totalSeconds' => 0];
                }
                $summaries[$staffKey]['closed']++;
                $summaries[$staffKey]['totalSeconds'] += $seconds;
            }
        }

        foreach ($summaries as &$summary) {
            $summary['averageSeconds'] = (int) round($summary['totalSeconds'] / $summary['closed']);
            $summary['totalDuration'] = $this->formatDuration($summary['totalSeconds']);
            $summary['averageDuration'] = $this->formatDuration($summary['averageSeconds']);
        }
        unset($summary);
        usort($ticketRows, static fn (array $left, array $right): int => $right['assignedAt'] <=> $left['assignedAt']);

        return ['summaries' => $summaries, 'ticketRows' => $ticketRows];
    }

    private function formatDuration(int $seconds): string
    {
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return $days > 0
            ? sprintf('%dd %02dh %02dm', $days, $hours, $minutes)
            : sprintf('%02dh %02dm', $hours, $minutes);
    }
}
