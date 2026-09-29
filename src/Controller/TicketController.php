<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Branch;
use App\Entity\Category;
use App\Entity\Department;
use App\Entity\Member;
use App\Entity\Ticket;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\StaffPermission;
use App\Enum\TicketChannel;
use App\Enum\TicketMessageVisibility;
use App\Enum\TicketPriority;
use App\Enum\TicketStatus;
use App\Enum\UserType;
use App\Security\SystemRole;
use App\Security\TicketAccessVoter;
use App\Service\Storage\TicketAttachmentService;
use App\Ticket\TicketService;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/tickets')]
class TicketController extends AbstractController
{
    private const PAGE_SIZE = 25;

    #[Route('', name: 'ticket_index', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $entityManager): Response
    {
        $user = $this->requireUser();
        $page = max(1, $request->query->getInt('page', 1));

        $qb = $entityManager->createQueryBuilder()
            ->select('t', 'm', 'd', 'c', 'a', 'b')
            ->from(Ticket::class, 't')
            ->join('t.member', 'm')
            ->join('t.department', 'd')
            ->join('t.category', 'c')
            ->leftJoin('t.assignedTo', 'a')
            ->leftJoin('t.branch', 'b')
            ->orderBy('t.updatedAt', 'DESC');

        $this->scopeTickets($qb, $user);

        // (debug removed)
        $this->applyTicketFilters($qb, $request, $entityManager);

        $countQb = clone $qb;
        $total = (int) $countQb->resetDQLPart('select')->resetDQLPart('orderBy')->select('COUNT(DISTINCT t.id)')->getQuery()->getSingleScalarResult();
        $tickets = $qb->setFirstResult(($page - 1) * self::PAGE_SIZE)->setMaxResults(self::PAGE_SIZE)->getQuery()->getResult();

        // Queue summary metrics for top cards (scoped to user)
        $metricsQb = $entityManager->createQueryBuilder()
            ->select('t.status, COUNT(t.id) AS cnt')
            ->from(Ticket::class, 't')
            ->groupBy('t.status');
        $this->scopeTickets($metricsQb, $user);
        $statusCounts = [];
        foreach ($metricsQb->getQuery()->getResult() as $row) {
            $statusVal = $row['status'] instanceof TicketStatus ? $row['status']->value : (string) $row['status'];
            $statusCounts[$statusVal] = (int) $row['cnt'];
        }
        $queueMetrics = [
            'total' => array_sum($statusCounts),
            'open' => ($statusCounts[TicketStatus::OPEN->value] ?? 0) + ($statusCounts[TicketStatus::REOPENED->value] ?? 0),
            'in_progress' => $statusCounts[TicketStatus::IN_PROGRESS->value] ?? 0,
            'waiting' => $statusCounts[TicketStatus::WAITING_FOR_MEMBER->value] ?? 0,
            'resolved' => ($statusCounts[TicketStatus::RESOLVED->value] ?? 0) + ($statusCounts[TicketStatus::CLOSED->value] ?? 0),
        ];

        return $this->render('tickets/index.html.twig', [
            'tickets' => $tickets,
            'queueMetrics' => $queueMetrics,
            'statuses' => TicketStatus::cases(),
            'priorities' => TicketPriority::cases(),
            'categories' => $entityManager->getRepository(Category::class)->findBy(['active' => true], ['name' => 'ASC']),
            'departments' => $entityManager->getRepository(Department::class)->findBy(['active' => true], ['name' => 'ASC']),
            'branches' => $entityManager->getRepository(Branch::class)->findBy(['active' => true], ['name' => 'ASC']),
            'channels' => TicketChannel::cases(),
            'staffMembers' => $this->staffMembersForFilters($entityManager, $user),
            'slaStatuses' => ['ON_TRACK', 'DUE_SOON', 'PAUSED', 'BREACHED', 'ESCALATED', 'MET', 'NOT_SET'],
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PAGE_SIZE)),
            'total' => $total,
        ]);
    }

    #[Route('/search-members', name: 'ticket_search_members', methods: ['GET'])]
    public function searchMembers(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        if (!($this->isGranted(SystemRole::STAFF->value) || $this->isGranted(SystemRole::SUPERVISOR->value) || $this->isGranted(SystemRole::ADMIN->value))) {
            throw $this->createAccessDeniedException('You must be staff to perform member searches.');
        }
        $query = trim((string) $request->query->get('q', ''));

        if (mb_strlen($query) < 2) {
            return new JsonResponse(['members' => []]);
        }

        $qb = $entityManager->createQueryBuilder()
            ->select('m', 'b')
            ->from(Member::class, 'm')
            ->leftJoin('m.branch', 'b')
            ->where('LOWER(m.memberNumber) LIKE :q')
            ->orWhere('LOWER(m.displayName) LIKE :q')
            ->orWhere('m.primaryPhone LIKE :rawQ')
            ->orWhere('LOWER(m.email) LIKE :q')
            ->setParameter('q', '%' . strtolower($query) . '%')
            ->setParameter('rawQ', '%' . $query . '%')
            ->setMaxResults(10);

        $results = [];
        /** @var list<Member> $members */
        $members = $qb->getQuery()->getResult();
        foreach ($members as $m) {
            $results[] = [
                'id' => $m->getId()->toRfc4122(),
                'memberNumber' => $m->getMemberNumber(),
                'displayName' => $m->getDisplayName(),
                'phone' => $m->getPrimaryPhone() ?? 'N/A',
                'email' => $m->getEmail() ?? 'N/A',
                'branch' => $m->getBranch()?->getName() ?? 'Not specified',
                'branchId' => $m->getBranch()?->getId()?->toRfc4122(),
                'status' => $m->getStatus()->value,
            ];
        }

        return new JsonResponse(['members' => $results]);
    }

    #[Route('/new', name: 'ticket_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager, TicketService $ticketService, TicketAttachmentService $attachmentService): Response
    {
        $user = $this->requireUser();

        $isStaff = $this->isGranted(SystemRole::STAFF->value) || $this->isGranted(SystemRole::SUPERVISOR->value) || $this->isGranted(SystemRole::ADMIN->value);
        $isMember = in_array(SystemRole::MEMBER->value, $user->getRoles(), true) || $user->getUserType() === UserType::MEMBER;

        if (!$isStaff && !$isMember) {
            $this->addFlash('error', 'You must sign in to create tickets.');
            return $this->redirectToRoute('app_login');
        }

        if ($isStaff && !$isMember && !$user->hasPermission(StaffPermission::CREATE_TICKETS)) {
            $this->addFlash('error', 'You do not have permission to create tickets on behalf of members.');
            return $this->redirectToRoute('ticket_index');
        }

        $categories = $entityManager->getRepository(Category::class)->findBy(['active' => true], ['name' => 'ASC']);
        $branches = $entityManager->getRepository(Branch::class)->findBy(['active' => true], ['name' => 'ASC']);
        $departments = $entityManager->getRepository(Department::class)->findBy(['active' => true], ['name' => 'ASC']);
        $channels = TicketChannel::cases();
        $priorities = TicketPriority::cases();

        $errors = [];
        $selectedMember = null;

        if ($isMember) {
            $selectedMember = $entityManager->getRepository(Member::class)->findOneBy(['user' => $user]);
            if (!$selectedMember instanceof Member) {
                $this->addFlash('error', 'Your user account is not linked to a SACCO member record. Please contact an administrator.');
                return $this->redirectToRoute('ticket_index');
            }
        } else {
            $memberId = (string) $request->request->get('member_id', $request->query->get('member_id', ''));
            if ($memberId !== '') {
                if (Uuid::isValid($memberId)) {
                    $selectedMember = $entityManager->getRepository(Member::class)->find($memberId);
                } else {
                    $selectedMember = $entityManager->getRepository(Member::class)->findOneBy(['memberNumber' => strtoupper(trim($memberId))]);
                }
            }
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('ticket_create', (string) $request->request->get('_token'))) {
                $errors[] = 'Your session expired. Please submit the form again.';
            }

            if (!$selectedMember instanceof Member) {
                $errors[] = 'Member not found. Please verify the member details or contact an administrator.';
            }

            $subject = trim((string) $request->request->get('subject'));
            $description = trim((string) $request->request->get('description'));
            $categoryId = (string) $request->request->get('category');
            $category = ($categoryId !== '' && Uuid::isValid($categoryId)) ? $entityManager->getRepository(Category::class)->find($categoryId) : null;
            $attachment = $request->files->get('attachment');

            if (!$category instanceof Category || !$category->isActive()) {
                $errors[] = 'Select a valid ticket category.';
            }

            if (mb_strlen($subject) < 5) {
                $errors[] = 'Subject must be at least 5 characters.';
            }

            if (mb_strlen($description) < 10) {
                $errors[] = 'Description must be at least 10 characters.';
            }

            if ($attachment instanceof UploadedFile) {
                $errors = array_merge($errors, $attachmentService->validate($attachment));
            }

            if ($errors === [] && $selectedMember instanceof Member && $category instanceof Category) {
                try {
                    if ($isMember) {
                        $ticket = $ticketService->createForMember(
                            $user,
                            $category,
                            $subject,
                            $description,
                            $request->getClientIp()
                        );
                    } else {
                        $branchId = (string) $request->request->get('branch');
                        $branch = ($branchId !== '' && Uuid::isValid($branchId)) ? $entityManager->getRepository(Branch::class)->find($branchId) : null;
                        $channelVal = (string) $request->request->get('channel');
                        $channel = TicketChannel::tryFrom($channelVal) ?? TicketChannel::PHONE;
                        $priorityVal = (string) $request->request->get('priority');
                        $priority = TicketPriority::tryFrom($priorityVal) ?? ($category->getDefaultPriority() ?? TicketPriority::MEDIUM);

                        $ticket = $ticketService->createByStaffForMember(
                            $user,
                            $selectedMember,
                            $category,
                            $subject,
                            $description,
                            $priority,
                            $channel,
                            $branch,
                            $request->getClientIp()
                        );
                    }

                    if ($attachment instanceof UploadedFile && $attachment->getError() !== UPLOAD_ERR_NO_FILE) {
                        $attachmentService->store($ticket, null, $user, $attachment, $request->getClientIp());
                    }

                    $this->addFlash('success', 'Ticket ' . $ticket->getReference() . ' was successfully created.');

                    return $this->redirectToRoute('ticket_show', ['id' => $ticket->getId()->toRfc4122()]);
                } catch (\Throwable $e) {
                    $errors[] = 'Could not create ticket: ' . $e->getMessage();
                }
            }
        }

        return $this->render('tickets/new.html.twig', [
            'categories' => $categories,
            'branches' => $branches,
            'departments' => $departments,
            'channels' => $channels,
            'priorities' => $priorities,
            'selectedMember' => $selectedMember,
            'isMember' => $isMember,
            'errors' => $errors,
        ]);
    }

    #[Route('/{id}', name: 'ticket_show', methods: ['GET'])]
    public function show(Ticket $ticket, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted(TicketAccessVoter::VIEW, $ticket);
        $user = $this->requireUser();
        $roles = $user->getRoles();
        $canCloseTicket = in_array(SystemRole::ADMIN->value, $roles, true)
            || ($this->sameDepartment($user, $ticket)
                && (in_array(SystemRole::SUPERVISOR->value, $roles, true)
                    || $user->hasPermission(StaffPermission::CLOSE_TICKETS)));

        $auditLogs = $entityManager->getRepository(\App\Entity\AuditLog::class)->findBy([
            'entityType' => 'ticket',
            'entityId' => $ticket->getId()->toRfc4122(),
        ], ['createdAt' => 'DESC']);

        return $this->render('tickets/show.html.twig', [
            'ticket' => $ticket,
            'availableStaff' => $this->staffForDepartment($entityManager, $ticket),
            'availableTeams' => $this->teamsForDepartment($entityManager, $ticket),
            'auditLogs' => $auditLogs,
            'canCloseTicket' => $canCloseTicket,
        ]);
    }

    #[Route('/{id}/accept', name: 'ticket_accept', methods: ['POST'])]
    public function accept(Ticket $ticket, Request $request, TicketService $ticketService): Response
    {
        $this->denyAccessUnlessGranted(TicketAccessVoter::ASSIGN, $ticket);
        $this->validateTicketToken($ticket, $request, 'ticket_accept');

        try {
            $ticketService->accept($ticket, $this->requireUser(), $request->getClientIp());
            $this->addFlash('success', 'Ticket accepted and moved to in progress.');
        } catch (DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('ticket_show', ['id' => $ticket->getId()->toRfc4122()]);
    }

    #[Route('/{id}/reply', name: 'ticket_reply', methods: ['POST'])]
    public function reply(Ticket $ticket, Request $request, TicketService $ticketService, TicketAttachmentService $attachmentService): Response
    {
        $this->denyAccessUnlessGranted(TicketAccessVoter::REPLY, $ticket);
        $this->validateTicketToken($ticket, $request, 'ticket_reply');

        $body = trim((string) $request->request->get('body'));
        $visibilityVal = (string) $request->request->get('visibility', 'PUBLIC');
        $visibility = TicketMessageVisibility::tryFrom($visibilityVal) ?? TicketMessageVisibility::PUBLIC;
        $attachment = $request->files->get('attachment');
        $errors = [];

        if ($body === '' || mb_strlen($body) < 3) {
            $errors[] = 'Write a response before submitting.';
        }

        if ($attachment instanceof UploadedFile) {
            $errors = array_merge($errors, $attachmentService->validate($attachment));
        }

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->addFlash('error', $error);
            }
        } else {
            try {
                $message = $ticketService->addReply($ticket, $this->requireUser(), $body, $visibility, $request->getClientIp());
                if ($attachment instanceof UploadedFile && $attachment->getError() !== UPLOAD_ERR_NO_FILE) {
                    $attachmentService->store($ticket, $message, $this->requireUser(), $attachment, $request->getClientIp());
                }
                $this->addFlash('success', $visibility === TicketMessageVisibility::INTERNAL ? 'Internal note added.' : 'Customer response saved.');
            } catch (DomainException|RuntimeException $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }

        return $this->redirectToRoute('ticket_show', ['id' => $ticket->getId()->toRfc4122()]);
    }

    #[Route('/{id}/request-info', name: 'ticket_request_info', methods: ['POST'])]
    public function requestInfo(Ticket $ticket, Request $request, TicketService $ticketService): Response
    {
        $this->denyAccessUnlessGranted(TicketAccessVoter::REPLY, $ticket);
        $this->validateTicketToken($ticket, $request, 'ticket_request_info');

        $question = trim((string) $request->request->get('question'));
        if ($question === '' || mb_strlen($question) < 8) {
            $this->addFlash('error', 'Add the information request before submitting.');
        } else {
            try {
                $ticketService->requestInformation($ticket, $this->requireUser(), $question, $request->getClientIp());
                $this->addFlash('success', 'Information request sent to the member. SLA timer is paused until the member responds.');
            } catch (DomainException $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }

        return $this->redirectToRoute('ticket_show', ['id' => $ticket->getId()->toRfc4122()]);
    }

    #[Route('/{id}/resolve', name: 'ticket_resolve', methods: ['POST'])]
    public function resolve(Ticket $ticket, Request $request, TicketService $ticketService): Response
    {
        $this->denyAccessUnlessGranted(TicketAccessVoter::REPLY, $ticket);
        $user = $this->requireUser();

        // Check if user has permission to resolve tickets
        if (!$user->hasPermission(StaffPermission::RESOLVE_TICKETS)) {
            throw $this->createAccessDeniedException('You do not have permission to resolve tickets.');
        }

        $this->validateTicketToken($ticket, $request, 'ticket_resolve');

        $resolution = trim((string) $request->request->get('resolution'));
        if ($resolution === '' || mb_strlen($resolution) < 10) {
            $this->addFlash('error', 'Add a clear resolution before resolving the ticket.');
        } else {
            try {
                $ticketService->resolve($ticket, $this->requireUser(), $resolution, $request->getClientIp());
                $this->addFlash('success', 'Ticket resolved and member notified.');
            } catch (DomainException $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }

        return $this->redirectToRoute('ticket_show', ['id' => $ticket->getId()->toRfc4122()]);
    }

    #[Route('/{id}/reassign', name: 'ticket_reassign', methods: ['POST'])]
    public function reassign(Ticket $ticket, Request $request, EntityManagerInterface $entityManager, TicketService $ticketService): Response
    {
        $this->denyAccessUnlessGranted(TicketAccessVoter::ASSIGN, $ticket);
        $user = $this->requireUser();

        // Check if user has permission to reassign tickets
        if (!$user->hasPermission(StaffPermission::REASSIGN_TICKETS)) {
            throw $this->createAccessDeniedException('You do not have permission to reassign tickets.');
        }

        $this->validateTicketToken($ticket, $request, 'ticket_reassign');

        $staffId = (string) $request->request->get('assigned_to');
        $assignedTo = $staffId !== '' ? $entityManager->getRepository(User::class)->find($staffId) : null;
        $teamId = (string) $request->request->get('assigned_team');
        $assignedTeam = $teamId !== '' ? $entityManager->getRepository(Team::class)->find($teamId) : null;

        if (!$assignedTo instanceof User) {
            $this->addFlash('error', 'Select a valid staff member.');
        } elseif ($teamId !== '' && !$assignedTeam instanceof Team) {
            $this->addFlash('error', 'Select a valid team.');
        } else {
            try {
                $ticketService->reassign($ticket, $assignedTo, $this->requireUser(), $request->getClientIp(), $assignedTeam);
                $this->addFlash('success', 'Ticket reassigned.');
            } catch (DomainException $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }

        return $this->redirectToRoute('ticket_show', ['id' => $ticket->getId()->toRfc4122()]);
    }

    #[Route('/{id}/escalate', name: 'ticket_escalate', methods: ['POST'])]
    public function escalate(Ticket $ticket, Request $request, TicketService $ticketService): Response
    {
        $this->denyAccessUnlessGranted(TicketAccessVoter::ESCALATE, $ticket);
        $user = $this->requireUser();

        // Check if user has permission to escalate tickets
        if (!$user->hasPermission(StaffPermission::ESCALATE_TICKETS)) {
            throw $this->createAccessDeniedException('You do not have permission to escalate tickets.');
        }

        $this->validateTicketToken($ticket, $request, 'ticket_escalate');

        $reason = trim((string) $request->request->get('reason')) ?: 'Manual supervisor escalation.';
        $ticketService->escalate($ticket, $this->requireUser(), $reason, $request->getClientIp());
        $this->addFlash('success', 'Ticket escalated.');

        return $this->redirectToRoute('ticket_show', ['id' => $ticket->getId()->toRfc4122()]);
    }

    #[Route('/{id}/close', name: 'ticket_close', methods: ['POST'])]
    public function close(Ticket $ticket, Request $request, TicketService $ticketService): Response
    {
        $user = $this->requireUser();
        $isMemberOwner = $this->isMemberOwner($ticket, $user);
        $roles = $user->getRoles();
        $canStaffClose = in_array(SystemRole::ADMIN->value, $roles, true)
            || (in_array(SystemRole::SUPERVISOR->value, $roles, true) && $this->sameDepartment($user, $ticket))
            || ($user->hasPermission(StaffPermission::CLOSE_TICKETS) && $this->sameDepartment($user, $ticket));

        if (!$isMemberOwner && !$canStaffClose) {
            throw $this->createAccessDeniedException('You do not have permission to close this ticket.');
        }

        $this->validateTicketToken($ticket, $request, 'ticket_close');

        try {
            if ($isMemberOwner) {
                $rating = $request->request->getInt('rating', 0);
                $ratingScore = $rating >= 1 && $rating <= 5 ? $rating : null;
                $ratingComment = trim((string) $request->request->get('rating_comment')) ?: null;
                $ticketService->closeByMember($ticket, $user, $ratingScore, $ratingComment, $request->getClientIp());
                $this->addFlash('success', 'Ticket closed. Thank you for confirming the resolution.');
            } else {
                $reason = trim((string) $request->request->get('reason')) ?: null;
                $ticketService->closeByStaff($ticket, $user, $reason, $request->getClientIp());
                $this->addFlash('success', 'Ticket closed.');
            }
        } catch (DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        } catch (\Throwable $exception) {
            $this->addFlash('error', 'Could not close ticket: ' . $exception->getMessage());
        }

        return $this->redirectToRoute('ticket_show', ['id' => $ticket->getId()->toRfc4122()]);
    }

    #[Route('/{id}/reopen', name: 'ticket_reopen', methods: ['POST'])]
    public function reopen(Ticket $ticket, Request $request, TicketService $ticketService): Response
    {
        $user = $this->requireUser();
        $isMemberOwner = $this->isMemberOwner($ticket, $user);
        $roles = $user->getRoles();
        $canStaffReopen = in_array(SystemRole::ADMIN->value, $roles, true)
            || (in_array(SystemRole::SUPERVISOR->value, $roles, true) && $this->sameDepartment($user, $ticket))
            || $this->isGranted(TicketAccessVoter::ASSIGN, $ticket);

        if (!$isMemberOwner && !$canStaffReopen) {
            throw $this->createAccessDeniedException('You do not have permission to reopen this ticket.');
        }

        $this->validateTicketToken($ticket, $request, 'ticket_reopen');

        $reason = trim((string) $request->request->get('reason'));
        if ($reason === '' || mb_strlen($reason) < 5) {
            $this->addFlash('error', 'Explain why the ticket is being reopened (minimum 5 characters).');
        } else {
            try {
                if ($isMemberOwner) {
                    $ticketService->reopenByMember($ticket, $user, $reason, $request->getClientIp());
                } else {
                    $ticketService->reopenByStaff($ticket, $user, $reason, $request->getClientIp());
                }
                $this->addFlash('success', 'Ticket reopened.');
            } catch (DomainException $exception) {
                $this->addFlash('error', $exception->getMessage());
            } catch (\Throwable $exception) {
                $this->addFlash('error', 'Could not reopen ticket: ' . $exception->getMessage());
            }
        }

        return $this->redirectToRoute('ticket_show', ['id' => $ticket->getId()->toRfc4122()]);
    }

    private function requireUser(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('You must sign in to continue.');
        }

        return $user;
    }

    /**
     * Returns true when the authenticated user is the member who owns the ticket.
     * A member user is linked to a Member record; we compare the member's linked user
     * to the currently authenticated user.
     */
    private function isMemberOwner(Ticket $ticket, User $user): bool
    {
        $memberUser = $ticket->getMember()->getUser();

        return $memberUser instanceof User && $memberUser->getId()->equals($user->getId());
    }

    /**
     * Returns true when the given staff user belongs to the same department as the ticket.
     */
    private function sameDepartment(User $user, Ticket $ticket): bool
    {
        return $user->getDepartment() !== null
            && $ticket->getDepartment()->getId()->equals($user->getDepartment()->getId());
    }

    private function scopeTickets(\Doctrine\ORM\QueryBuilder $qb, User $user): void
    {
        $roles = $user->getRoles();

        if (in_array(SystemRole::ADMIN->value, $roles, true)) {
            return;
        }

        if (in_array(SystemRole::SUPERVISOR->value, $roles, true)) {
            $department = $user->getDepartment();

            if ($department !== null) {
                $qb->andWhere('(t.assignedTo = :user OR t.department = :department)')
                    ->setParameter('user', $user)
                    ->setParameter('department', $department);
            } else {
                $qb->andWhere('t.assignedTo = :user')
                    ->setParameter('user', $user);
            }

            return;
        }

        if (in_array(SystemRole::STAFF->value, $roles, true)) {
            $department = $user->getDepartment();

            // Staff who can act on the department queue must be able to find its tickets.
            $canSeeDept = $department !== null && (
                $user->hasPermission(StaffPermission::VIEW_ALL_DEPARTMENT_TICKETS)
                || $user->hasPermission(StaffPermission::RESOLVE_TICKETS)
                || $user->hasPermission(StaffPermission::CLOSE_TICKETS)
                || $user->hasPermission(StaffPermission::ASSIGN_TICKETS)
                || $user->hasPermission(StaffPermission::REASSIGN_TICKETS)
                || $user->hasPermission(StaffPermission::EDIT_TICKET_PRIORITY)
                || $user->hasPermission(StaffPermission::EDIT_TICKET_CATEGORY)
            );

            if ($canSeeDept) {
                $qb->andWhere('(t.assignedTo = :user OR t.createdBy = :user OR t.department = :department)')
                    ->setParameter('user', $user)
                    ->setParameter('department', $department);
            } else {
                // Show only tickets explicitly assigned to or created by this staff member
                $qb->andWhere('(t.assignedTo = :user OR t.createdBy = :user)')
                    ->setParameter('user', $user);
            }

            return;
        }

        $aliases = $qb->getAllAliases();
        if (!in_array('m', $aliases, true)) {
            $qb->join('t.member', 'm');
        }
        if (!in_array('memberUser', $aliases, true)) {
            $qb->join('m.user', 'memberUser');
        }
        $qb->andWhere('memberUser = :user')
            ->setParameter('user', $user);
    }

    private function applyTicketFilters(\Doctrine\ORM\QueryBuilder $qb, Request $request, EntityManagerInterface $entityManager): void
    {
        $q = trim((string) $request->query->get('q'));
        if ($q !== '') {
            $qb->andWhere('(LOWER(t.reference) LIKE :q OR LOWER(t.subject) LIKE :q OR LOWER(m.memberNumber) LIKE :q OR LOWER(m.displayName) LIKE :q OR (m.primaryPhone IS NOT NULL AND LOWER(m.primaryPhone) LIKE :q))')
                ->setParameter('q', '%' . strtolower($q) . '%');
        }

        $status = TicketStatus::tryFrom((string) $request->query->get('status'));
        if ($status instanceof TicketStatus) {
            $qb->andWhere('t.status = :status')->setParameter('status', $status);
        }

        $priority = TicketPriority::tryFrom((string) $request->query->get('priority'));
        if ($priority instanceof TicketPriority) {
            $qb->andWhere('t.priority = :priority')->setParameter('priority', $priority);
        }

        foreach (['department' => 'd', 'category' => 'c', 'assigned_to' => 'a'] as $param => $alias) {
            $value = (string) $request->query->get($param);
            if ($value !== '') {
                $qb->andWhere($alias . '.id = :' . $param)->setParameter($param, $value);
            }
        }

        $branchId = (string) $request->query->get('branch');
        if ($branchId !== '') {
            $qb->andWhere('b.id = :branchId')->setParameter('branchId', $branchId);
        }

        $channelVal = (string) $request->query->get('channel');
        if ($channelVal !== '') {
            $channel = TicketChannel::tryFrom($channelVal);
            if ($channel instanceof TicketChannel) {
                $qb->andWhere('t.channel = :channel')->setParameter('channel', $channel);
            }
        }

        if ($request->query->getBoolean('overdue')) {
            $qb->andWhere('t.slaDueAt IS NOT NULL')
                ->andWhere('t.slaDueAt < :now')
                ->andWhere('t.firstRespondedAt IS NULL')
                ->andWhere('t.status IN (:activeStatuses)')
                ->setParameter('now', new \DateTimeImmutable())
                ->setParameter('activeStatuses', [TicketStatus::OPEN, TicketStatus::IN_PROGRESS, TicketStatus::WAITING_FOR_MEMBER, TicketStatus::REOPENED]);
        }

        $slaStatus = (string) $request->query->get('sla_status');
        if ($slaStatus === 'ESCALATED') {
            $qb->andWhere('t.escalatedAt IS NOT NULL');
        } elseif ($slaStatus === 'BREACHED') {
            $qb->andWhere('t.escalatedAt IS NULL')
                ->andWhere('t.slaDueAt IS NOT NULL')
                ->andWhere('t.slaDueAt < :slaNow')
                ->andWhere('t.firstRespondedAt IS NULL')
                ->setParameter('slaNow', new \DateTimeImmutable());
        }
    }

    private function validateTicketToken(Ticket $ticket, Request $request, string $action): void
    {
        if (!$this->isCsrfTokenValid($action . '_' . $ticket->getId()->toRfc4122(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid ticket action token.');
        }
    }

    private function assertMemberOwnsTicket(Ticket $ticket): void
    {
        $user = $this->requireUser();
        $memberUser = $ticket->getMember()->getUser();

        if (!$memberUser instanceof User || !$memberUser->getId()->equals($user->getId())) {
            throw $this->createAccessDeniedException('You can only perform this action on your own ticket.');
        }
    }

    /** @return list<User> */
    private function staffForDepartment(EntityManagerInterface $entityManager, Ticket $ticket): array
    {
        return $entityManager->createQueryBuilder()
            ->select('u', 'r')
            ->from(User::class, 'u')
            ->join('u.roles', 'r')
            ->where('u.active = true')
            ->andWhere('u.department = :department')
            ->andWhere('r.code = :role')
            ->setParameter('department', $ticket->getDepartment())
            ->setParameter('role', SystemRole::STAFF->value)
            ->orderBy('u.fullName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<Team> */
    private function teamsForDepartment(EntityManagerInterface $entityManager, Ticket $ticket): array
    {
        return $entityManager->createQueryBuilder()
            ->select('team')
            ->from(Team::class, 'team')
            ->where('team.active = true')
            ->andWhere('team.department = :department')
            ->setParameter('department', $ticket->getDepartment())
            ->orderBy('team.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<User> */
    private function staffMembersForFilters(EntityManagerInterface $entityManager, User $user): array
    {
        $qb = $entityManager->createQueryBuilder()
            ->select('u', 'r')
            ->from(User::class, 'u')
            ->join('u.roles', 'r')
            ->where('u.active = true')
            ->andWhere('r.code = :role')
            ->setParameter('role', SystemRole::STAFF->value)
            ->orderBy('u.fullName', 'ASC');

        if (!in_array(SystemRole::ADMIN->value, $user->getRoles(), true)) {
            $qb->andWhere('u.department = :department')->setParameter('department', $user->getDepartment());
        }

        return $qb->getQuery()->getResult();
    }
}