<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Category;
use App\Entity\Ticket;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\StaffPermission;
use App\Enum\TicketStatus;
use App\Security\SystemRole;
use App\Security\TicketAccessVoter;
use App\Ticket\TicketService;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/tickets')]
class TicketApiController extends AbstractController
{
    #[Route('', name: 'api_ticket_index', methods: ['GET'])]
    public function index(EntityManagerInterface $entityManager): JsonResponse
    {
        $user = $this->requireUser();
        $qb = $entityManager->createQueryBuilder()
            ->select('t', 'm', 'd', 'c', 'a', 'team')
            ->from(Ticket::class, 't')
            ->join('t.member', 'm')
            ->join('t.department', 'd')
            ->join('t.category', 'c')
            ->leftJoin('t.assignedTo', 'a')
            ->leftJoin('t.assignedTeam', 'team')
            ->orderBy('t.updatedAt', 'DESC')
            ->setMaxResults(100);

        $roles = $user->getRoles();
        if (!in_array(SystemRole::ADMIN->value, $roles, true)) {
            if (in_array(SystemRole::STAFF->value, $roles, true) || in_array(SystemRole::SUPERVISOR->value, $roles, true)) {
                $qb->where('t.assignedTo = :user OR t.department = :department')
                    ->setParameter('user', $user)
                    ->setParameter('department', $user->getDepartment());
            } else {
                $qb->join('m.user', 'memberUser')->where('memberUser = :user')->setParameter('user', $user);
            }
        }

        return $this->json(array_map(fn (Ticket $ticket): array => $this->serializeTicket($ticket), $qb->getQuery()->getResult()));
    }

    #[Route('', name: 'api_ticket_create', methods: ['POST'])]
    public function create(Request $request, EntityManagerInterface $entityManager, TicketService $ticketService): JsonResponse
    {
        $this->denyAccessUnlessGranted(SystemRole::MEMBER->value);
        $payload = $this->payload($request);
        $category = isset($payload['category_id']) ? $entityManager->getRepository(Category::class)->find((string) $payload['category_id']) : null;

        if (!$category instanceof Category || !$category->isActive()) {
            return $this->json(['message' => 'Select a valid ticket category.'], 422);
        }

        try {
            $ticket = $ticketService->createForMember(
                $this->requireUser(),
                $category,
                (string) ($payload['subject'] ?? ''),
                (string) ($payload['description'] ?? ''),
                $request->getClientIp()
            );
        } catch (\Throwable $exception) {
            return $this->json(['message' => $exception->getMessage()], 422);
        }

        return $this->json($this->serializeTicket($ticket), 201);
    }

    #[Route('/{id}', name: 'api_ticket_show', methods: ['GET'])]
    public function show(Ticket $ticket): JsonResponse
    {
        $this->denyAccessUnlessGranted(TicketAccessVoter::VIEW, $ticket);

        return $this->json($this->serializeTicket($ticket, true));
    }

    #[Route('/{id}/messages', name: 'api_ticket_message', methods: ['POST'])]
    public function message(Ticket $ticket, Request $request, TicketService $ticketService): JsonResponse
    {
        $this->denyAccessUnlessGranted(TicketAccessVoter::REPLY, $ticket);
        $payload = $this->payload($request);

        try {
            $message = $ticketService->addReply($ticket, $this->requireUser(), (string) ($payload['body'] ?? ''), $request->getClientIp());
        } catch (DomainException $exception) {
            return $this->json(['message' => $exception->getMessage()], 422);
        }

        return $this->json(['id' => $message->getId()->toRfc4122(), 'created_at' => $message->getCreatedAt()->format(DATE_ATOM)], 201);
    }

    #[Route('/{id}/accept', name: 'api_ticket_accept', methods: ['POST'])]
    public function accept(Ticket $ticket, Request $request, TicketService $ticketService): JsonResponse
    {
        $this->denyAccessUnlessGranted(TicketAccessVoter::ASSIGN, $ticket);

        try {
            $ticketService->accept($ticket, $this->requireUser(), $request->getClientIp());
        } catch (DomainException $exception) {
            return $this->json(['message' => $exception->getMessage()], 422);
        }

        return $this->json($this->serializeTicket($ticket));
    }

    #[Route('/{id}/reassign', name: 'api_ticket_reassign', methods: ['POST'])]
    public function reassign(Ticket $ticket, Request $request, EntityManagerInterface $entityManager, TicketService $ticketService): JsonResponse
    {
        $this->denyAccessUnlessGranted(TicketAccessVoter::ASSIGN, $ticket);
        $actor = $this->requireUser();

        if (!$actor->hasPermission(StaffPermission::REASSIGN_TICKETS)) {
            throw $this->createAccessDeniedException('You do not have permission to reassign tickets.');
        }

        $payload = $this->payload($request);
        $assignedTo = isset($payload['assigned_to'])
            ? $entityManager->getRepository(User::class)->find((string) $payload['assigned_to'])
            : null;
        $assignedTeam = isset($payload['assigned_team']) && $payload['assigned_team'] !== ''
            ? $entityManager->getRepository(Team::class)->find((string) $payload['assigned_team'])
            : null;

        if (!$assignedTo instanceof User) {
            return $this->json(['message' => 'Select a valid staff member.'], 422);
        }

        if (isset($payload['assigned_team']) && $payload['assigned_team'] !== '' && !$assignedTeam instanceof Team) {
            return $this->json(['message' => 'Select a valid team.'], 422);
        }

        try {
            $ticketService->reassign($ticket, $assignedTo, $actor, $request->getClientIp(), $assignedTeam);
        } catch (DomainException $exception) {
            return $this->json(['message' => $exception->getMessage()], 422);
        }

        return $this->json($this->serializeTicket($ticket));
    }

    #[Route('/{id}/resolve', name: 'api_ticket_resolve', methods: ['POST'])]
    public function resolve(Ticket $ticket, Request $request, TicketService $ticketService): JsonResponse
    {
        $this->denyAccessUnlessGranted(TicketAccessVoter::REPLY, $ticket);
        $payload = $this->payload($request);

        try {
            $ticketService->resolve($ticket, $this->requireUser(), (string) ($payload['resolution'] ?? ''), $request->getClientIp());
        } catch (DomainException $exception) {
            return $this->json(['message' => $exception->getMessage()], 422);
        }

        return $this->json($this->serializeTicket($ticket));
    }

    #[Route('/{id}/reopen', name: 'api_ticket_reopen', methods: ['POST'])]
    public function reopen(Ticket $ticket, Request $request, TicketService $ticketService): JsonResponse
    {
        $memberUser = $ticket->getMember()->getUser();
        if (!$memberUser instanceof User || !$memberUser->getId()->equals($this->requireUser()->getId())) {
            throw $this->createAccessDeniedException('You can only reopen your own tickets.');
        }

        $payload = $this->payload($request);
        try {
            $ticketService->reopenByMember($ticket, $this->requireUser(), (string) ($payload['reason'] ?? ''), $request->getClientIp());
        } catch (DomainException $exception) {
            return $this->json(['message' => $exception->getMessage()], 422);
        }

        return $this->json($this->serializeTicket($ticket));
    }

    private function payload(Request $request): array
    {
        $payload = json_decode($request->getContent(), true);

        return is_array($payload) ? $payload : [];
    }

    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authentication is required.');
        }

        return $user;
    }

    private function serializeTicket(Ticket $ticket, bool $includeMessages = false): array
    {
        $data = [
            'id' => $ticket->getId()->toRfc4122(),
            'reference' => $ticket->getReference(),
            'subject' => $ticket->getSubject(),
            'status' => $ticket->getStatus()->value,
            'priority' => $ticket->getPriority()->value,
            'department' => $ticket->getDepartment()->getName(),
            'category' => $ticket->getCategory()->getName(),
            'assigned_to' => $ticket->getAssignedTo()?->getFullName(),
            'assigned_team_id' => $ticket->getAssignedTeam()?->getId()?->toRfc4122(),
            'assigned_team' => $ticket->getAssignedTeam()?->getName(),
            'sla_due_at' => $ticket->getSlaDueAt()?->format(DATE_ATOM),
            'sla_status' => $ticket->getSlaStatus(),
            'created_at' => $ticket->getCreatedAt()->format(DATE_ATOM),
            'updated_at' => $ticket->getUpdatedAt()->format(DATE_ATOM),
        ];

        if ($includeMessages) {
            $data['messages'] = array_map(static fn ($message): array => [
                'id' => $message->getId()->toRfc4122(),
                'author' => $message->getAuthor()?->getFullName(),
                'body' => $message->getBody(),
                'created_at' => $message->getCreatedAt()->format(DATE_ATOM),
            ], $ticket->getMessages()->toArray());
        }

        return $data;
    }
}