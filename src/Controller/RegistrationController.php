<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Member;
use App\Entity\Role;
use App\Entity\User;
use App\Enum\MemberStatus;
use App\Enum\NotificationChannel;
use App\Enum\UserType;
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

class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'member_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        EntityManagerInterface $entityManager,
        NotificationRouter $notificationRouter,
    ): Response {
        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('member_register', (string) $request->request->get('_token'))) {
                $errors[] = 'Your session expired. Please submit the form again.';
            }

            $memberNumber = strtoupper(trim((string) $request->request->get('member_number')));
            $contact = strtolower(trim((string) $request->request->get('contact')));
            $member = $memberNumber !== '' ? $entityManager->getRepository(Member::class)->findOneBy(['memberNumber' => $memberNumber]) : null;

            if (!$member instanceof Member || $member->getStatus() !== MemberStatus::ACTIVE || $member->getUser() instanceof User) {
                $errors[] = 'We could not verify that member record for portal registration.';
            } elseif (!$this->contactMatches($member, $contact)) {
                $errors[] = 'The contact detail does not match the member record.';
            }

            if ($errors === [] && $member instanceof Member) {
                $otp = (string) random_int(100000, 999999);
                $verificationUrl = $this->generateUrl('member_register_verify', [], UrlGeneratorInterface::ABSOLUTE_URL);
                $request->getSession()->set('member_registration', [
                    'member_id' => $member->getId()->toRfc4122(),
                    'otp_hash' => hash('sha256', $otp),
                    'expires_at' => (new \DateTimeImmutable('+10 minutes'))->format(DATE_ATOM),
                ]);

                $notificationRouter->sendDirectEmail(
                    (string) $member->getEmail(),
                    $member->getDisplayName(),
                    'Verify your Mudi SACCO portal registration',
                    "Hello {$member->getDisplayName()},\n\nUse this verification code to complete your portal registration:\n\n{$otp}\n\nAlternatively open the registration page here:\n{$verificationUrl}\n\nThis code expires in 10 minutes.",
                    ['code' => $otp, 'verification_url' => $verificationUrl]
                );

                if ($this->getParameter('kernel.environment') !== 'prod') {
                    $this->addFlash('success', 'Development OTP: ' . $otp);
                }

                return $this->redirectToRoute('member_register_verify');
            }
        }

        return $this->render('registration/register.html.twig', [
            'errors' => $errors,
        ]);
    }

    #[Route('/register/verify', name: 'member_register_verify', methods: ['GET', 'POST'])]
    public function verify(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        AuditLogger $auditLogger,
        NotificationRouter $notificationRouter,
    ): Response {
        $sessionData = $request->getSession()->get('member_registration');
        if (!is_array($sessionData) || !isset($sessionData['member_id'], $sessionData['otp_hash'], $sessionData['expires_at'])) {
            return $this->redirectToRoute('member_register');
        }

        $member = $entityManager->getRepository(Member::class)->find((string) $sessionData['member_id']);
        if (!$member instanceof Member || $member->getUser() instanceof User) {
            $request->getSession()->remove('member_registration');
            return $this->redirectToRoute('member_register');
        }

        $errors = [];
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('member_register_verify', (string) $request->request->get('_token'))) {
                $errors[] = 'Your session expired. Please submit the form again.';
            }

            $expiresAt = new \DateTimeImmutable((string) $sessionData['expires_at']);
            if ($expiresAt <= new \DateTimeImmutable()) {
                $errors[] = 'The verification code has expired. Start registration again.';
            }

            $otp = trim((string) $request->request->get('otp'));
            if (!hash_equals((string) $sessionData['otp_hash'], hash('sha256', $otp))) {
                $errors[] = 'The verification code is not valid.';
            }

            $password = (string) $request->request->get('password');
            $confirmPassword = (string) $request->request->get('confirm_password');
            if (strlen($password) < 12) {
                $errors[] = 'Password must be at least 12 characters.';
            }
            if ($password !== $confirmPassword) {
                $errors[] = 'Passwords do not match.';
            }

            $email = $member->getEmail();
            if ($email === null || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'This member record does not have a valid email address for portal login.';
            } elseif ($entityManager->getRepository(User::class)->findOneBy(['email' => $email]) instanceof User) {
                $errors[] = 'An account already exists for this email address.';
            }

            $memberRole = $entityManager->getRepository(Role::class)->findOneBy(['code' => SystemRole::MEMBER->value]);
            if (!$memberRole instanceof Role) {
                $errors[] = 'Member role is not configured. Ask an administrator to seed foundation data.';
            }

            if ($errors === [] && $email !== null && $memberRole instanceof Role) {
                $user = (new User())
                    ->setEmail($email)
                    ->setFullName($member->getDisplayName())
                    ->setUserType(UserType::MEMBER)
                    ->setActive(true)
                    ->setPasswordChangeRequired(false)
                    ->setPasswordHash('');
                $user->setPasswordHash($passwordHasher->hashPassword($user, $password));
                $user->addRole($memberRole);
                $member->setUser($user);

                $entityManager->persist($user);
                $entityManager->flush();

                $notificationRouter->sendDirectEmail(
                    $email,
                    $member->getDisplayName(),
                    'Your Mudi SACCO portal account is ready',
                    "Hello {$member->getDisplayName()},\n\nYour Mudi SACCO support portal account has been created successfully.\n\nEmail: {$email}\n\nSign in here: {$this->generateUrl('app_login', [], UrlGeneratorInterface::ABSOLUTE_URL)}\n\nIf you need help, contact the SACCO help desk.",
                    ['event' => 'MEMBER_ACCOUNT_READY', 'email' => $email, 'login_url' => $this->generateUrl('app_login', [], UrlGeneratorInterface::ABSOLUTE_URL)]
                );

                $notificationRouter->notify(
                    $user,
                    'Your portal account is ready',
                    'Your Mudi SACCO support portal account has been created and is ready to use.',
                    ['event' => 'MEMBER_ACCOUNT_READY', 'member_number' => $member->getMemberNumber()],
                    NotificationChannel::IN_APP,
                );

                $auditLogger->record($user, 'member.registered', 'member', $member->getId(), [
                    'member_number' => $member->getMemberNumber(),
                    'email' => $email,
                ], $request->getClientIp());
                $entityManager->flush();
                $request->getSession()->remove('member_registration');

                $this->addFlash('success', 'Registration complete. You can now sign in.');

                return $this->redirectToRoute('app_login');
            }
        }

        return $this->render('registration/verify.html.twig', [
            'member' => $member,
            'errors' => $errors,
        ]);
    }

    private function contactMatches(Member $member, string $contact): bool
    {
        $email = $member->getEmail() !== null ? strtolower($member->getEmail()) : null;
        $phone = $member->getPrimaryPhone() !== null ? preg_replace('/\D+/', '', $member->getPrimaryPhone()) : null;
        $normalizedContact = preg_replace('/\D+/', '', $contact);

        return ($email !== null && $contact === $email)
            || ($phone !== null && $normalizedContact !== '' && str_ends_with($phone, $normalizedContact));
    }
}