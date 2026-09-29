<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Category;
use App\Entity\Department;
use App\Entity\Member;
use App\Entity\Permission;
use App\Entity\Role;
use App\Entity\Team;
use App\Entity\Ticket;
use App\Entity\User;
use App\Enum\StaffPermission;
use App\Security\TicketAccessVoter;
use App\Security\SystemRole;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

class TicketAccessVoterTest extends TestCase
{
    public function testAssignedTeamMemberCanReplyWhenNoOfficerIsAssigned(): void
    {
        $department = $this->department('LOANS');
        $team = (new Team())->setCode('LOAN_INQUIRY')->setName('Loan Inquiry')->setDepartment($department);
        $staff = $this->staffUser($department, withHandlePermission: true);
        $team->addMember($staff);
        $ticket = $this->ticket($department)->setAssignedTeam($team);

        $this->assertTrue($this->vote(TicketAccessVoter::REPLY, $ticket, $staff));
    }

    public function testStaffOutsideAssignedTeamCannotReplyToTeamTicket(): void
    {
        $department = $this->department('LOANS');
        $team = (new Team())->setCode('LOAN_INQUIRY')->setName('Loan Inquiry')->setDepartment($department);
        $staff = $this->staffUser($department, withHandlePermission: true);
        $ticket = $this->ticket($department)->setAssignedTeam($team);

        $this->assertFalse($this->vote(TicketAccessVoter::REPLY, $ticket, $staff));
    }

    public function testStaffWithResolvePermissionCanReplyToAssignedDepartmentTicket(): void
    {
        $department = $this->department('IT');
        $staff = $this->staffUser($department, withHandlePermission: false)
            ->grantPermission(StaffPermission::RESOLVE_TICKETS);
        $ticket = $this->ticket($department)->setAssignedTo($staff);

        $this->assertTrue($this->vote(TicketAccessVoter::REPLY, $ticket, $staff));
    }

    public function testStaffWithResolvePermissionCanAcceptUnassignedDepartmentTicket(): void
    {
        $department = $this->department('IT');
        $staff = $this->staffUser($department, withHandlePermission: false)
            ->grantPermission(StaffPermission::RESOLVE_TICKETS);
        $ticket = $this->ticket($department);

        $this->assertTrue($this->vote(TicketAccessVoter::ASSIGN, $ticket, $staff));
    }

    public function testStaffWithResolvePermissionCannotAcceptTicketOutsideDepartment(): void
    {
        $staff = $this->staffUser($this->department('IT'), withHandlePermission: false)
            ->grantPermission(StaffPermission::RESOLVE_TICKETS);
        $ticket = $this->ticket($this->department('FINANCE'));

        $this->assertFalse($this->vote(TicketAccessVoter::ASSIGN, $ticket, $staff));
    }

    public function testSupervisorWithEscalationPermissionCanEscalateDepartmentTicket(): void
    {
        $department = $this->department('LOANS');
        $supervisor = $this->supervisorUser($department, withEscalationPermission: true);
        $ticket = $this->ticket($department);

        $this->assertTrue($this->vote(TicketAccessVoter::ESCALATE, $ticket, $supervisor));
    }

    public function testSupervisorCannotEscalateTicketOutsideTheirDepartment(): void
    {
        $supervisor = $this->supervisorUser($this->department('LOANS'), withEscalationPermission: true);
        $ticket = $this->ticket($this->department('FINANCE'));

        $this->assertFalse($this->vote(TicketAccessVoter::ESCALATE, $ticket, $supervisor));
    }

    private function vote(string $attribute, Ticket $ticket, User $user): bool
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return (new TestableTicketAccessVoter())->voteFor($attribute, $ticket, $token);
    }

    private function ticket(Department $department): Ticket
    {
        $member = (new Member())->setMemberNumber('M-001')->setDisplayName('Member');
        $category = (new Category())->setCode('LOAN')->setName('Loan')->setDepartment($department);

        return (new Ticket())
            ->setMember($member)
            ->setDepartment($department)
            ->setCategory($category);
    }

    private function staffUser(Department $department, bool $withHandlePermission): User
    {
        $role = (new Role())->setCode(SystemRole::STAFF->value)->setName('Staff');
        if ($withHandlePermission) {
            $role->addPermission((new Permission())->setCode('tickets.handle')->setName('Handle tickets'));
        }

        return (new User())
            ->setEmail('staff@example.test')
            ->setDepartment($department)
            ->addRole($role);
    }

    private function supervisorUser(Department $department, bool $withEscalationPermission): User
    {
        $role = (new Role())->setCode(SystemRole::SUPERVISOR->value)->setName('Supervisor');
        if ($withEscalationPermission) {
            $role->addPermission((new Permission())->setCode('tickets.escalate')->setName('Escalate tickets'));
        }

        return (new User())
            ->setEmail('supervisor@example.test')
            ->setDepartment($department)
            ->addRole($role);
    }

    private function department(string $code): Department
    {
        return (new Department())->setCode($code)->setName($code);
    }
}

final class TestableTicketAccessVoter extends TicketAccessVoter
{
    public function voteFor(string $attribute, Ticket $ticket, TokenInterface $token): bool
    {
        return $this->voteOnAttribute($attribute, $ticket, $token);
    }
}
