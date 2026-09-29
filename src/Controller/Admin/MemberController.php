<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Branch;
use App\Entity\Member;
use App\Enum\MemberStatus;
use App\Security\SystemRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/admin/members')]
class MemberController extends AbstractController
{
    #[Route('', name: 'admin_members_index', methods: ['GET'])]
    public function index(EntityManagerInterface $entityManager, Request $request): Response
    {
        $this->denyAccessUnlessGranted(SystemRole::ADMIN->value);

        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 50;
        $offset = ($page - 1) * $limit;
        $search = trim((string) $request->query->get('search', ''));

        $qb = $entityManager->createQueryBuilder()
            ->select('m', 'b')
            ->from(Member::class, 'm')
            ->leftJoin('m.branch', 'b');

        if ($search) {
            $qb->where('m.memberNumber LIKE :search OR m.displayName LIKE :search OR m.email LIKE :search')
                ->setParameter('search', "%$search%");
        }

        $total = (int) (clone $qb)
            ->select('COUNT(m.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $members = $qb
            ->orderBy('m.memberNumber', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $this->render('admin/members/index.html.twig', [
            'members' => $members,
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'search' => $search,
        ]);
    }

    #[Route('/new', name: 'admin_members_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted(SystemRole::ADMIN->value);

        $member = new Member();
        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('member_create', (string) $request->request->get('_token'))) {
                $errors[] = 'Your session expired. Please submit the form again.';
            }

            $memberNumber = strtoupper(trim((string) $request->request->get('member_number', '')));
            $displayName = trim((string) $request->request->get('display_name', ''));
            $primaryPhone = trim((string) $request->request->get('primary_phone', ''));
            $email = strtolower(trim((string) $request->request->get('email', '')));
            $status = (string) $request->request->get('status', 'ACTIVE');
            $branchId = (string) $request->request->get('branch_id', '');

            if (!$memberNumber) {
                $errors[] = 'Member number is required.';
            }
            if (!$displayName) {
                $errors[] = 'Display name is required.';
            }

            if (!$errors) {
                $existing = $entityManager->getRepository(Member::class)->findOneBy(['memberNumber' => $memberNumber]);
                if ($existing instanceof Member) {
                    $errors[] = 'A member with this number already exists.';
                }
            }

            if (!$errors) {
                try {
                    $member->setMemberNumber($memberNumber);
                    $member->setDisplayName($displayName);
                    $member->setPrimaryPhone($primaryPhone ?: null);
                    $member->setEmail($email ?: null);
                    $member->setStatus(MemberStatus::tryFrom(strtoupper($status)) ?? MemberStatus::ACTIVE);

                    if ($branchId !== '' && Uuid::isValid($branchId)) {
                        $branch = $entityManager->getRepository(Branch::class)->find($branchId);
                        if ($branch instanceof Branch) {
                            $member->setBranch($branch);
                        }
                    }

                    $entityManager->persist($member);
                    $entityManager->flush();

                    $this->addFlash('success', "Member {$memberNumber} created successfully.");
                    return $this->redirectToRoute('admin_members_index');
                } catch (\Throwable $e) {
                    $errors[] = 'Could not save member: ' . $e->getMessage();
                }
            }
        }

        $branches = $entityManager->getRepository(Branch::class)
            ->findBy(['active' => true], ['name' => 'ASC']);

        return $this->render('admin/members/form.html.twig', [
            'member' => $member,
            'branches' => $branches,
            'statuses' => MemberStatus::cases(),
            'errors' => $errors,
            'mode' => 'new',
        ]);
    }

    #[Route('/{id}/edit', name: 'admin_members_edit', methods: ['GET', 'POST'])]
    public function edit(Member $member, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted(SystemRole::ADMIN->value);

        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('member_edit_' . $member->getId()->toRfc4122(), (string) $request->request->get('_token'))) {
                $errors[] = 'Your session expired. Please submit the form again.';
            }

            $displayName = trim((string) $request->request->get('display_name', ''));
            $primaryPhone = trim((string) $request->request->get('primary_phone', ''));
            $email = strtolower(trim((string) $request->request->get('email', '')));
            $status = (string) $request->request->get('status', 'ACTIVE');
            $branchId = (string) $request->request->get('branch_id', '');

            if (!$displayName) {
                $errors[] = 'Display name is required.';
            }

            if (!$errors) {
                try {
                    $member->setDisplayName($displayName);
                    $member->setPrimaryPhone($primaryPhone ?: null);
                    $member->setEmail($email ?: null);
                    $member->setStatus(MemberStatus::tryFrom(strtoupper($status)) ?? MemberStatus::ACTIVE);

                    if ($branchId !== '' && Uuid::isValid($branchId)) {
                        $branch = $entityManager->getRepository(Branch::class)->find($branchId);
                        $member->setBranch($branch instanceof Branch ? $branch : null);
                    } else {
                        $member->setBranch(null);
                    }

                    $entityManager->flush();
                    $this->addFlash('success', "Member {$member->getMemberNumber()} updated successfully.");
                    return $this->redirectToRoute('admin_members_index');
                } catch (\Throwable $e) {
                    $errors[] = 'Could not update member: ' . $e->getMessage();
                }
            }
        }

        $branches = $entityManager->getRepository(Branch::class)
            ->findBy(['active' => true], ['name' => 'ASC']);

        return $this->render('admin/members/form.html.twig', [
            'member' => $member,
            'branches' => $branches,
            'statuses' => MemberStatus::cases(),
            'errors' => $errors,
            'mode' => 'edit',
        ]);
    }

    #[Route('/{id}/delete', name: 'admin_members_delete', methods: ['POST'])]
    public function delete(Member $member, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted(SystemRole::ADMIN->value);

        if (!$this->isCsrfTokenValid('member_delete_' . $member->getId()->toRfc4122(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid token.');
            return $this->redirectToRoute('admin_members_index');
        }

        try {
            $memberNumber = $member->getMemberNumber();
            $entityManager->remove($member);
            $entityManager->flush();

            $this->addFlash('success', "Member $memberNumber deleted.");
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Cannot delete member because they have associated tickets or audit records.');
        }

        return $this->redirectToRoute('admin_members_index');
    }

    #[Route('/import-csv', name: 'admin_members_import', methods: ['GET', 'POST'])]
    public function importCsv(Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted(SystemRole::ADMIN->value);
        $errors = [];
        $result = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('member_import', (string) $request->request->get('_token'))) {
                $errors[] = 'Your session expired. Please submit the form again.';
            }

            $file = $request->files->get('csv_file');
            if (!$file) {
                $errors[] = 'Please select a CSV file to upload.';
            } elseif (!in_array($file->getMimeType(), ['text/csv', 'text/plain', 'application/vnd.ms-excel'], true)) {
                $errors[] = 'Invalid file type. Please upload a CSV file.';
            }

            if (empty($errors)) {
                try {
                    $result = $this->processImport(
                        $file,
                        $entityManager,
                        (bool) $request->request->get('skip_existing', false)
                    );

                    if (empty($result['errors'])) {
                        $this->addFlash('success', "Successfully imported {$result['created']} members, updated {$result['updated']}, skipped {$result['skipped']}.");
                    } else {
                        $this->addFlash('warning', "Import completed with {$result['error_count']} errors. Created: {$result['created']}, Updated: {$result['updated']}.");
                    }
                } catch (\Exception $e) {
                    $errors[] = 'Import failed: ' . $e->getMessage();
                }
            }
        }

        return $this->render('admin/members/import.html.twig', [
            'errors' => $errors,
            'result' => $result,
        ]);
    }

    private function processImport($file, EntityManagerInterface $entityManager, bool $skipExisting): array
    {
        $result = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => [],
            'error_count' => 0,
        ];

        $stream = fopen($file->getPathname(), 'r');
        if ($stream === false) {
            throw new \RuntimeException('Could not open file');
        }

        $branchRepository = $entityManager->getRepository(Branch::class);
        $memberRepository = $entityManager->getRepository(Member::class);

        $lineNumber = 0;

        // Skip header row
        fgetcsv($stream);

        while (($row = fgetcsv($stream)) !== null) {
            $lineNumber++;

            if (empty($row[0])) {
                continue;
            }

            try {
                $memberNumber = strtoupper(trim($row[0]));
                $displayName = trim($row[1] ?? '');
                $primaryPhone = trim($row[2] ?? '');
                $email = strtolower(trim($row[3] ?? ''));
                $status = strtolower(trim($row[4] ?? 'active'));
                $branchCode = strtoupper(trim($row[5] ?? ''));

                if (!$memberNumber || !$displayName) {
                    $result['errors'][] = "Line $lineNumber: Member number and display name required";
                    $result['error_count']++;
                    continue;
                }

                $member = $memberRepository->findOneBy(['memberNumber' => $memberNumber]);

                if ($member instanceof Member) {
                    if ($skipExisting) {
                        $result['skipped']++;
                        continue;
                    }
                    $member->setDisplayName($displayName);
                    if ($primaryPhone) {
                        $member->setPrimaryPhone($primaryPhone);
                    }
                    if ($email) {
                        $member->setEmail($email);
                    }
                    $member->setStatus(MemberStatus::tryFrom($status) ?? MemberStatus::ACTIVE);
                    $result['updated']++;
                } else {
                    $member = new Member();
                    $member->setMemberNumber($memberNumber);
                    $member->setDisplayName($displayName);
                    if ($primaryPhone) {
                        $member->setPrimaryPhone($primaryPhone);
                    }
                    if ($email) {
                        $member->setEmail($email);
                    }
                    $member->setStatus(MemberStatus::tryFrom($status) ?? MemberStatus::ACTIVE);

                    if ($branchCode) {
                        $branch = $branchRepository->findOneBy(['code' => $branchCode]);
                        if ($branch instanceof Branch) {
                            $member->setBranch($branch);
                        }
                    }

                    $entityManager->persist($member);
                    $result['created']++;
                }

                if (($result['created'] + $result['updated']) % 100 === 0) {
                    $entityManager->flush();
                }
            } catch (\Exception $e) {
                $result['errors'][] = "Line $lineNumber: " . $e->getMessage();
                $result['error_count']++;
            }
        }

        fclose($stream);
        $entityManager->flush();

        return $result;
    }
}
