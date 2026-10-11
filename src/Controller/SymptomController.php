<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\SymptomDay;
use App\Entity\SymptomEntry;
use App\Entity\SymptomType;
use App\Entity\User;
use App\Repository\SymptomDayRepository;
use App\Repository\SymptomTypeRepository;
use App\Service\SymptomTypeManager;
use App\Service\UserTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

class SymptomController extends AbstractController
{
    public function __construct(private readonly UserTime $userTime)
    {
    }

    #[Route('/symptoms', name: 'app_symptoms', methods: ['GET'])]
    public function index(
        Request $request,
        SymptomTypeRepository $types,
        SymptomDayRepository $days,
        SymptomTypeManager $typeManager,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $typeManager->ensureDefaultsFor($user);

        // Allow backfilling a past date via ?date=YYYY-MM-DD (never the future).
        $selected = $this->parseDate((string) $request->query->get('date', ''), $user);
        $day      = $days->findOneByUserAndDate($user, $selected);

        return $this->render('symptoms/index.html.twig', [
            'selectedDate' => $selected,
            'today'        => $this->userTime->startOfToday($user),
            'day'          => $day,
            'levels'       => $day !== null ? $day->levelsByTypeId() : [],
            'enabledTypes' => $types->findEnabledByUserOrdered($user),
            'allTypes'     => $types->findByUserOrdered($user),
            'recent'       => $days->findRecent($user, 60),
        ]);
    }

    /**
     * Upserts the day's note and a level per enabled symptom. Used by both the
     * Symptoms page and the Dashboard quick-entry card (each posts level_<typeId>).
     */
    #[Route('/symptoms/save', name: 'app_symptoms_save', methods: ['POST'])]
    public function save(
        Request $request,
        SymptomTypeRepository $types,
        SymptomDayRepository $days,
        EntityManagerInterface $em,
        TranslatorInterface $translator,
    ): Response {
        $redirect = $request->request->get('redirect') === 'dashboard'
            ? 'app_dashboard'
            : 'app_symptoms';

        if (!$this->isCsrfTokenValid('symptom-save', (string) $request->request->get('_token'))) {
            return $this->redirectToRoute($redirect);
        }

        /** @var User $user */
        $user = $this->getUser();
        $date = $this->parseDate((string) $request->request->get('logged_date'), $user);

        $day = $days->findOneByUserAndDate($user, $date);
        if ($day === null) {
            $day = (new SymptomDay())->setUser($user)->setLoggedDate($date);
            $em->persist($day);
        }

        // Existing entries keyed by type id, so we upsert rather than duplicate.
        $existing = [];
        foreach ($day->getEntries() as $entry) {
            $type = $entry->getSymptomType();
            if ($type !== null) {
                $existing[$type->getId()] = $entry;
            }
        }

        foreach ($types->findEnabledByUserOrdered($user) as $type) {
            $raw = $request->request->get('level_' . $type->getId());
            if ($raw === null) {
                continue; // not on this form (e.g. dashboard posts only its sliders)
            }
            $entry = $existing[$type->getId()] ?? null;
            if ($entry === null) {
                $entry = (new SymptomEntry())->setSymptomType($type);
                $day->addEntry($entry);
                $em->persist($entry);
            }
            $entry->setLevel((int) $raw);
        }

        // Only the full page submits a note; don't let the dashboard clear it.
        if ($request->request->has('note')) {
            $note = trim((string) $request->request->get('note', ''));
            $day->setNote($note !== '' ? $note : null);
        }

        $day->touch();
        $em->flush();

        $this->addFlash('success', $translator->trans('symptom_page.saved'));

        return $this->redirectToRoute($redirect);
    }

    #[Route('/symptoms/types', name: 'app_symptom_type_create', methods: ['POST'])]
    public function createType(
        Request $request,
        SymptomTypeRepository $types,
        EntityManagerInterface $em,
        SluggerInterface $slugger,
        TranslatorInterface $translator,
    ): Response {
        if (!$this->isCsrfTokenValid('symptom-type-create', (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_symptoms');
        }

        /** @var User $user */
        $user  = $this->getUser();
        $label = trim((string) $request->request->get('label', ''));
        if ($label === '') {
            $this->addFlash('error', $translator->trans('symptom_page.name_required'));

            return $this->redirectToRoute('app_symptoms');
        }
        $label = mb_substr($label, 0, 100);

        // Slug + collision-guard the key within this user.
        $base = strtolower($slugger->slug($label)->toString()) ?: 'symptom';
        $existing = $types->existingKeysFor($user);
        $key = $base;
        $n = 2;
        while (in_array($key, $existing, true)) {
            $key = $base . '-' . $n;
            ++$n;
        }

        $type = (new SymptomType())
            ->setUser($user)
            ->setTypeKey($key)
            ->setLabel($label)
            ->setBuiltin(false)
            ->setEnabled(true)
            ->setHigherIsBetter($request->request->getBoolean('higher_is_better'))
            ->setPosition($types->maxPositionFor($user) + 1);

        $em->persist($type);
        $em->flush();

        $this->addFlash('success', $translator->trans('symptom_page.added', ['name' => $label]));

        return $this->redirectToRoute('app_symptoms');
    }

    #[Route(
        '/symptoms/types/{id}/toggle',
        name: 'app_symptom_type_toggle',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    public function toggleType(
        int $id,
        Request $request,
        SymptomTypeRepository $types,
        EntityManagerInterface $em,
    ): Response {
        $type = $this->ownedType($id, $types);

        if ($this->isCsrfTokenValid('symptom-type-toggle-' . $id, (string) $request->request->get('_token'))) {
            $type->setEnabled(!$type->isEnabled());
            $em->flush();
        }

        return $this->redirectToRoute('app_symptoms');
    }

    #[Route(
        '/symptoms/types/{id}/delete',
        name: 'app_symptom_type_delete',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    public function deleteType(
        int $id,
        Request $request,
        SymptomTypeRepository $types,
        EntityManagerInterface $em,
        TranslatorInterface $translator,
    ): Response {
        $type = $this->ownedType($id, $types);

        if ($this->isCsrfTokenValid('symptom-type-delete-' . $id, (string) $request->request->get('_token'))) {
            if ($type->isBuiltin()) {
                $this->addFlash('error', $translator->trans('symptom_page.builtin_no_delete'));
            } else {
                // symptom_entry rows cascade via the DB ON DELETE CASCADE.
                $em->remove($type);
                $em->flush();
                $this->addFlash('success', $translator->trans('symptom_page.removed'));
            }
        }

        return $this->redirectToRoute('app_symptoms');
    }

    private function ownedType(int $id, SymptomTypeRepository $types): SymptomType
    {
        $type = $types->find($id);
        if ($type === null || $type->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException();
        }

        return $type;
    }

    private function parseDate(string $raw, User $user): \DateTimeImmutable
    {
        // "Today" and the future-guard are evaluated in the user's timezone.
        $today = $this->userTime->startOfToday($user);
        if ($raw === '') {
            return $today;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw, $this->userTime->zone($user));
        if ($date === false || $date > $today) {
            return $today;
        }

        return $date;
    }
}
