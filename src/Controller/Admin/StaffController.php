<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Department;
use App\Entity\PasswordResetToken;
use App\Entity\Role;
use App\Entity\User;
use App\Enum\StaffPermission;
use App\Enum\UserType;
use App\Security\PasswordReset\PasswordResetService;
use App\Security\SystemRole;
use App\Service\Audit\AuditLogger;
use App\Service\Notification\NotificationRouter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route('/admin/staff')]
class StaffController extends AbstractController
{
    #[Route('', name: 'admin_staff_index', methods: ['GET'])]
    public function index(EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted(SystemRole::ADMIN->value);

        $employees = $entityManager->createQueryBuilder()
            ->select('u', 'd', 'r')
            ->from(User::class, 'u')
            ->leftJoin('u.department', 'd')
            ->leftJoin('u.roles', 'r')
            ->where('u.userType IN (:types)')
            ->setParameter('types', [UserType::STAFF, UserType::SUPERVISOR, UserType::ADMINISTRATOR])
            ->orderBy('u.active', 'DESC')
            ->addOrderBy('u.fullName', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->render('admin/staff/index.html.twig', [
            'employees' => $employees,
        ]);
    }

    #[Route('/new', name: 'admin_staff_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        PasswordResetService $passwordResetService,
        AuditLogger $auditLogger,
        NotificationRouter $notificationRouter,
    ): Response {
        $this->denyAccessUnlessGranted(SystemRole::ADMIN->value);

        $employee = new User();
        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('staff_create', (string) $request->request->get('_token'))) {
                $errors[] = 'Your session expired. Please submit the form again.';
            }

            $errors = array_merge($errors, $this->applyEmployeeInput($employee, $request, $entityManager));

            $existing = $entityManager->getRepository(User::class)->findOneBy(['email' => $employee->getEmail()]);
            if ($existing instanceof User) {
                $errors[] = 'An account with this email already exists.';
            }

            if ($errors === []) {
                $employee->setPasswordHash($passwordHasher->hashPassword($employee, bin2hex(random_bytes(24))));
                $employee->setPasswordChangeRequired(true);
                $entityManager->persist($employee);

                $tokenData = $passwordResetService->createToken($employee, PasswordResetToken::PURPOSE_SETUP, $this->requireAdmin(), $request->getClientIp());
                $setupUrl = $this->generateUrl('password_reset', ['token' => $tokenData['token']], UrlGeneratorInterface::ABSOLUTE_URL);

                // Send account setup email directly to the staff member's email address
                $notificationRouter->sendDirectEmail(
                    $employee->getEmail(),
                    $employee->getFullName(),
                    'Set up your Mudi SACCO staff account',
                    sprintf(
                        "Hello %s,\n\nAn account has been created for you on the Mudi SACCO Help Desk.\n\nRole: %s\nDepartment: %s\n\nPlease click the secure link below to set your password and access the portal:\n%s\n\nThis setup link is valid for 1 hour. If you have any questions, please contact your administrator.",
                        $employee->getFullName(),
                        $this->primaryRole($employee),
                        $employee->getDepartment()?->getName() ?? 'General',
                        $setupUrl
                    ),
                    ['link' => $setupUrl]
                );

                $auditLogger->record($this->requireAdmin(), 'staff.created', 'user', $employee->getId(), [
                    'email' => $employee->getEmail(),
                    'role' => $this->primaryRole($employee),
                    'department' => $employee->getDepartment()?->getCode(),
                    'setup_required' => true,
                ], $request->getClientIp());
                $entityManager->flush();

                $this->addFlash('success', 'Employee account created and invitation email sent to ' . $employee->getEmail() . '.');

                // Store setup URL in session and redirect to dedicated page — never show it in a flash.
                $request->getSession()->set('staff_setup_link', [
                    'url'  => $setupUrl,
                    'name' => $employee->getFullName(),
                    'purpose' => 'setup',
                ]);

                return $this->redirectToRoute('admin_staff_setup_link');
            }
        }

        return $this->render('admin/staff/form.html.twig', [
            'employee' => $employee,
            'departments' => $this->activeDepartments($entityManager),
            'roles' => $this->employeeRoles($entityManager),
            'allPermissions' => StaffPermission::cases(),
            'errors' => $errors,
            'mode' => 'new',
        ]);
    }

    #[Route('/{id}/edit', name: 'admin_staff_edit', methods: ['GET', 'POST'])]
    public function edit(
        User $employee,
        Request $request,
        EntityManagerInterface $entityManager,
        AuditLogger $auditLogger,
    ): Response {
        $this->denyAccessUnlessGranted(SystemRole::ADMIN->value);
        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('staff_edit_' . $employee->getId()->toRfc4122(), (string) $request->request->get('_token'))) {
                $errors[] = 'Your session expired. Please submit the form again.';
            }

            $errors = array_merge($errors, $this->applyEmployeeInput($employee, $request, $entityManager));

            $existing = $entityManager->getRepository(User::class)->findOneBy(['email' => $employee->getEmail()]);
            if ($existing instanceof User && !$existing->getId()->equals($employee->getId())) {
                $errors[] = 'Another account already uses this email.';
            }

            if ($this->isSelf($employee) && !$employee->isActive()) {
                $errors[] = 'You cannot deactivate your own administrator account.';
                $employee->setActive(true);
            }

            if ($errors === []) {
                $auditLogger->record($this->requireAdmin(), 'staff.updated', 'user', $employee->getId(), [
                    'email' => $employee->getEmail(),
                    'role' => $this->primaryRole($employee),
                    'department' => $employee->getDepartment()?->getCode(),
                    'active' => $employee->isActive(),
                    'permissions' => $employee->getStaffPermissions(),
                ], $request->getClientIp());
                $entityManager->flush();
                $this->addFlash('success', 'Employee account updated.');

                return $this->redirectToRoute('admin_staff_index');
            }
        }

        return $this->render('admin/staff/form.html.twig', [
            'employee' => $employee,
            'departments' => $this->activeDepartments($entityManager),
            'roles' => $this->employeeRoles($entityManager),
            'allPermissions' => StaffPermission::cases(),
            'errors' => $errors,
            'mode' => 'edit',
        ]);
    }

    #[Route('/{id}/toggle-active', name: 'admin_staff_toggle_active', methods: ['POST'])]
    public function toggleActive(User $employee, Request $request, EntityManagerInterface $entityManager, AuditLogger $auditLogger): Response
    {
        $this->denyAccessUnlessGranted(SystemRole::ADMIN->value);

        if (!$this->isCsrfTokenValid('staff_toggle_' . $employee->getId()->toRfc4122(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid staff action token.');
        }

        if ($this->isSelf($employee)) {
            $this->addFlash('error', 'You cannot deactivate your own administrator account.');

            return $this->redirectToRoute('admin_staff_index');
        }

        $employee->setActive(!$employee->isActive());
        $auditLogger->record($this->requireAdmin(), $employee->isActive() ? 'staff.activated' : 'staff.deactivated', 'user', $employee->getId(), [
            'email' => $employee->getEmail(),
            'active' => $employee->isActive(),
        ], $request->getClientIp());
        $entityManager->flush();

        $this->addFlash('success', $employee->isActive() ? 'Employee activated.' : 'Employee deactivated.');

        return $this->redirectToRoute('admin_staff_index');
    }

    #[Route('/{id}/reset-password', name: 'admin_staff_reset_password', methods: ['POST'])]
    public function resetPassword(
        User $employee,
        Request $request,
        EntityManagerInterface $entityManager,
        PasswordResetService $passwordResetService,
        AuditLogger $auditLogger,
    ): Response {
        $this->denyAccessUnlessGranted(SystemRole::ADMIN->value);

        if (!$this->isCsrfTokenValid('staff_reset_' . $employee->getId()->toRfc4122(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid password reset token.');
        }

        $employee->setPasswordChangeRequired(true);
        $tokenData = $passwordResetService->createToken($employee, PasswordResetToken::PURPOSE_RESET, $this->requireAdmin(), $request->getClientIp());
        $resetUrl = $this->generateUrl('password_reset', ['token' => $tokenData['token']], UrlGeneratorInterface::ABSOLUTE_URL);

        $auditLogger->record($this->requireAdmin(), 'staff.password_reset_requested', 'user', $employee->getId(), [
            'email' => $employee->getEmail(),
        ], $request->getClientIp());
        $entityManager->flush();

        // Store reset URL in session and redirect to dedicated page — never show it in a flash.
        $request->getSession()->set('staff_setup_link', [
            'url'     => $resetUrl,
            'name'    => $employee->getFullName(),
            'purpose' => 'reset',
        ]);

        return $this->redirectToRoute('admin_staff_setup_link');
    }

    #[Route('/setup-link', name: 'admin_staff_setup_link', methods: ['GET'])]
    public function setupLink(Request $request): Response
    {
        $this->denyAccessUnlessGranted(SystemRole::ADMIN->value);

        $linkData = $request->getSession()->get('staff_setup_link');
        if (!is_array($linkData) || !isset($linkData['url'])) {
            return $this->redirectToRoute('admin_staff_index');
        }

        // Consume from session immediately — link is single-display only.
        $request->getSession()->remove('staff_setup_link');

        return $this->render('admin/staff/setup_link.html.twig', [
            'linkData' => $linkData,
        ]);
    }

    /** @return list<string> */
    private function applyEmployeeInput(User $employee, Request $request, EntityManagerInterface $entityManager): array
    {
        $errors = [];
        $fullName = trim((string) $request->request->get('full_name'));
        $email = strtolower(trim((string) $request->request->get('email')));
        $roleCode = (string) $request->request->get('role');
        $departmentId = (string) $request->request->get('department');
        $active = $request->request->getBoolean('active', true);

        if ($fullName === '' || mb_strlen($fullName) < 3) {
            $errors[] = 'Full name is required.';
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Use a valid work email address.';
        }

        $allowedRoles = [SystemRole::STAFF->value, SystemRole::SUPERVISOR->value, SystemRole::ADMIN->value];
        $role = $entityManager->getRepository(Role::class)->findOneBy(['code' => $roleCode]);
        if (!$role instanceof Role || !in_array($roleCode, $allowedRoles, true)) {
            $errors[] = 'Select a valid employee role.';
        }

        $department = null;
        if ($departmentId !== '') {
            $department = $entityManager->getRepository(Department::class)->find($departmentId);
        }

        if (in_array($roleCode, [SystemRole::STAFF->value, SystemRole::SUPERVISOR->value], true) && !$department instanceof Department) {
            $errors[] = 'Staff and supervisor accounts must be assigned to a department.';
        }

        if ($errors !== []) {
            return $errors;
        }

        $employee->setFullName($fullName);
        $employee->setEmail($email);
        $employee->setUserType(match ($roleCode) {
            SystemRole::ADMIN->value => UserType::ADMINISTRATOR,
            SystemRole::SUPERVISOR->value => UserType::SUPERVISOR,
            default => UserType::STAFF,
        });
        $employee->setDepartment(in_array($roleCode, [SystemRole::STAFF->value, SystemRole::SUPERVISOR->value], true) ? $department : null);
        $employee->setActive($active);

        foreach ($employee->getRoleEntities()->toArray() as $existingRole) {
            $employee->removeRole($existingRole);
        }
        $employee->addRole($role);

        // Set default permissions based on role
        $permissions = match ($roleCode) {
            SystemRole::ADMIN->value => StaffPermission::defaultForAdmin(),
            SystemRole::SUPERVISOR->value => StaffPermission::defaultForSupervisor(),
            default => StaffPermission::defaultForStaff(),
        };

        // Allow admin to customize permissions via checkboxes
        $customPermissions = array_filter(
            array_map(
                fn($p) => $p->value,
                StaffPermission::cases()
            ),
            fn($perm) => $request->request->getBoolean('permission_' . $perm, false)
        );

        if (!empty($customPermissions)) {
            $permissions = $customPermissions;
        }

        $employee->setStaffPermissions($permissions);

        return [];
    }

    /** @return list<Department> */
    private function activeDepartments(EntityManagerInterface $entityManager): array
    {
        return $entityManager->getRepository(Department::class)->findBy(['active' => true], ['name' => 'ASC']);
    }

    /** @return list<Role> */
    private function employeeRoles(EntityManagerInterface $entityManager): array
    {
        return $entityManager->createQueryBuilder()
            ->select('r')
            ->from(Role::class, 'r')
            ->where('r.code IN (:roles)')
            ->setParameter('roles', [SystemRole::STAFF->value, SystemRole::SUPERVISOR->value, SystemRole::ADMIN->value])
            ->orderBy('r.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    private function primaryRole(User $employee): string
    {
        foreach ($employee->getRoles() as $role) {
            if ($role !== 'ROLE_USER') {
                return $role;
            }
        }

        return 'ROLE_USER';
    }

    private function requireAdmin(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('You must sign in as an administrator.');
        }

        return $user;
    }

    private function isSelf(User $employee): bool
    {
        $user = $this->getUser();

        return $user instanceof User && $employee->getId()->equals($user->getId());
    }
}