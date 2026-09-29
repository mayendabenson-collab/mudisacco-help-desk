<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Ticket;
use App\Entity\User;
use App\Enum\StaffPermission;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class TicketAccessVoter extends Voter
{
    public const VIEW = 'ticket.view';
    public const REPLY = 'ticket.reply';
    public const ASSIGN = 'ticket.assign';
    public const ESCALATE = 'ticket.escalate';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::REPLY, self::ASSIGN, self::ESCALATE], true) && $subject instanceof Ticket;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User || !$user->isActive() || !$subject instanceof Ticket) {
            return false;
        }

        $roles = $user->getRoles();

        if (in_array(SystemRole::ADMIN->value, $roles, true)) {
            return true;
        }

        if ($this->isMemberOwner($user, $subject)) {
            return in_array($attribute, [self::VIEW, self::REPLY], true);
        }

        if (in_array(SystemRole::SUPERVISOR->value, $roles, true)) {
            return $this->supervisorVote($attribute, $user, $subject);
        }

        if (!in_array(SystemRole::STAFF->value, $roles, true)) {
            return false;
        }

        return $this->staffVote($attribute, $user, $subject);
    }

    private function supervisorVote(string $attribute, User $user, Ticket $ticket): bool
    {
        if (!$this->sameDepartment($user, $ticket)) {
            return false;
        }

        return match ($attribute) {
            self::VIEW => true,
            self::REPLY => $this->hasPermission($user, Permission::HANDLE_TICKETS),
            self::ASSIGN => $this->hasPermission($user, Permission::ASSIGN_TICKETS)
                || $user->hasPermission(StaffPermission::RESOLVE_TICKETS),
            self::ESCALATE => $this->hasPermission($user, Permission::ESCALATE_TICKETS),
            default => false,
        };
    }

    private function staffVote(string $attribute, User $user, Ticket $ticket): bool
    {
        return match ($attribute) {
            self::VIEW => $this->sameDepartment($user, $ticket) || $this->isAssignedTo($user, $ticket) || $this->isAssignedTeamMember($user, $ticket) || $this->isCreatorStaff($user, $ticket),
            self::REPLY => ($this->isAssignedTo($user, $ticket) || $this->isAssignedTeamMember($user, $ticket) || $this->isCreatorStaff($user, $ticket)) && $this->hasPermission($user, Permission::HANDLE_TICKETS),
            self::ASSIGN => $this->canStaffAccept($user, $ticket)
                && ($this->hasPermission($user, Permission::ASSIGN_TICKETS)
                    || $user->hasPermission(StaffPermission::RESOLVE_TICKETS)),
            self::ESCALATE => ($this->isAssignedTo($user, $ticket) || $this->isAssignedTeamMember($user, $ticket) || $this->isCreatorStaff($user, $ticket)) && $this->hasPermission($user, Permission::ESCALATE_TICKETS),
            default => false,
        };
    }

    private function isCreatorStaff(User $user, Ticket $ticket): bool
    {
        return $ticket->getCreatedBy() instanceof User && $ticket->getCreatedBy()->getId()->equals($user->getId());
    }

    private function canStaffAccept(User $user, Ticket $ticket): bool
    {
        if (!$this->sameDepartment($user, $ticket)) {
            return false;
        }

        return !($ticket->getAssignedTo() instanceof User)
            && (!$ticket->getAssignedTeam() instanceof \App\Entity\Team || $this->isAssignedTeamMember($user, $ticket))
            || $this->isAssignedTo($user, $ticket)
            || $this->hasPermission($user, Permission::ASSIGN_TICKETS);
    }

    private function isMemberOwner(User $user, Ticket $ticket): bool
    {
        $memberUser = $ticket->getMember()->getUser();

        return $memberUser instanceof User && $memberUser->getId()->equals($user->getId());
    }

    private function isAssignedTo(User $user, Ticket $ticket): bool
    {
        return $ticket->getAssignedTo() instanceof User && $ticket->getAssignedTo()->getId()->equals($user->getId());
    }

    private function isAssignedTeamMember(User $user, Ticket $ticket): bool
    {
        return $ticket->getAssignedTeam() !== null && $user->belongsToTeam($ticket->getAssignedTeam());
    }

    private function sameDepartment(User $user, Ticket $ticket): bool
    {
        return $user->getDepartment() !== null
            && $ticket->getDepartment()->getId()->equals($user->getDepartment()->getId());
    }

    /** @param list<Permission> $permissions */
    private function hasAnyPermission(User $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($user, $permission)) {
                return true;
            }
        }

        return false;
    }

    private function hasPermission(User $user, Permission $expectedPermission): bool
    {
        if ($expectedPermission === Permission::HANDLE_TICKETS && $user->hasPermission(StaffPermission::RESOLVE_TICKETS)) {
            return true;
        }

        foreach ($user->getRoleEntities() as $role) {
            foreach ($role->getPermissions() as $permission) {
                if ($permission->getCode() === $expectedPermission->value) {
                    return true;
                }
            }
        }

        return false;
    }
}