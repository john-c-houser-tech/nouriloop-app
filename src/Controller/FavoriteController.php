<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Enum\MealType;
use App\Repository\FavoriteRepository;
use App\Repository\MealEntryRepository;
use App\Entity\Favorite;
use App\Service\FavoriteManager;
use App\Service\PortionScaler;
use App\Service\UserTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/meal')]
class FavoriteController extends AbstractController
{
    /**
     * The favorites picker in the logging flow: a searchable, sortable list the
     * user can log from without taking a photo.
     */
    #[Route('/favorites', name: 'app_favorites', methods: ['GET'])]
    public function index(
        Request $request,
        FavoriteRepository $favorites,
        UserTime $userTime,
        PortionScaler $portionScaler,
    ): Response {
        $sort = (string) $request->query->get('sort', 'recent');
        $sort = in_array($sort, FavoriteRepository::SORTS, true) ? $sort : 'recent';

        /** @var User $user */
        $user = $this->getUser();
        $list = $favorites->findByUserOrdered($user, $sort);

        // Whole-batch quantities for the recipe reference collapse — the
        // amounts a cook follows. Stored verbatim since the batch_ingredients
        // column; imports predating it reconstruct per-serving × servings
        // (2-decimal rounding from the import division can drift slightly).
        $batchIngredients = [];
        foreach ($list as $favorite) {
            $servings = $favorite->getServingsAtImport();
            $batch = $favorite->getBatchIngredients()
                ?? ($servings !== null ? $portionScaler->scaleIngredients($favorite->getIngredients(), $servings) : []);
            if ($batch !== []) {
                $batchIngredients[$favorite->getId()] = $batch;
            }
        }

        return $this->render('favorite/index.html.twig', [
            'favorites' => $list,
            'sort'      => $sort,
            'mealTypes' => MealType::cases(),
            // Preselect by the user's local time of day, like the photo log page.
            'defaultMealType' => MealType::defaultForHour($userTime->localHour($user)),
            'savings'   => FavoriteManager::savings($list),
            'batchIngredients' => $batchIngredients,
        ]);
    }

    /**
     * Saves an already-logged meal as a favorite, copying its post-correction
     * nutrition, ingredients, and thumbnail. Tokens-per-analysis is unknown for
     * a stored meal (the analysis cost wasn't kept), so it's recorded as 0.
     */
    #[Route('/favorite/new', name: 'app_favorite_new', methods: ['POST'])]
    public function new(
        Request $request,
        MealEntryRepository $meals,
        FavoriteManager $favorites,
        TranslatorInterface $translator,
    ): Response {
        $id = (int) $request->request->get('meal_id');
        $entry = $meals->find($id);

        if (!$entry || $entry->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('favorite-new-' . $id, (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_meal_show', ['id' => $id]);
        }

        /** @var User $user */
        $user = $this->getUser();
        $nutrition = $entry->getNutritionData();

        $favorite = $favorites->create(
            $user,
            (string) $request->request->get('name', ''),
            $nutrition?->toArray() ?? [],
            $entry->getIngredients(),
            $entry->getAiDescription(),
            $entry->getImagePath(),
            0,
        );

        $this->addFlash('success', $translator->trans('favorite.saved', ['name' => $favorite->getName()]));

        return $this->redirectToRoute('app_meal_show', ['id' => $id]);
    }

    /**
     * Logs a new meal from a favorite at the chosen portion — reusing the saved
     * analysis, so no Claude call.
     */
    #[Route('/favorite/{id}/log', name: 'app_favorite_log', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function log(
        int $id,
        Request $request,
        FavoriteRepository $favorites,
        FavoriteManager $manager,
        PortionScaler $portionScaler,
        TranslatorInterface $translator,
    ): Response {
        $favorite = $favorites->find($id);

        if (!$favorite || $favorite->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('favorite-log-' . $id, (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_favorites');
        }

        $portions = $portionScaler->normalize($request->request->get('portions', 1));
        $mealType = MealType::tryFrom((string) $request->request->get('meal_type')) ?? MealType::Snack;
        $notes = trim((string) $request->request->get('notes', '')) ?: null;

        $entry = $manager->logFromFavorite($favorite, $portions, $mealType, $notes);

        $this->addFlash('success', $translator->trans('favorite.logged', ['name' => $favorite->getName()]));

        return $this->redirectToRoute('app_meal_show', ['id' => $entry->getId()]);
    }

    /**
     * Renames a favorite and updates its headline macros. Micronutrients and
     * confidence are left as captured; blank macro fields are left unchanged.
     */
    #[Route('/favorite/{id}/edit', name: 'app_favorite_edit', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function edit(
        int $id,
        Request $request,
        FavoriteRepository $favorites,
        EntityManagerInterface $em,
        TranslatorInterface $translator,
    ): Response {
        $favorite = $this->ownedFavorite($id, $favorites);

        if (!$this->isCsrfTokenValid('favorite-edit-' . $id, (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_favorites');
        }

        $name = trim((string) $request->request->get('name', ''));
        if ($name !== '') {
            $favorite->setName(mb_substr($name, 0, 200));
        }

        $nutrients = $favorite->getNutrients();
        foreach (['calories', 'protein', 'carbohydrates', 'fat'] as $key) {
            $raw = trim((string) $request->request->get($key, ''));
            if (is_numeric($raw)) {
                $nutrients[$key] = (float) $raw;
            }
        }
        $favorite->setNutrients($nutrients);

        $em->flush();
        $this->addFlash('success', $translator->trans('favorite.updated', ['name' => $favorite->getName()]));

        return $this->redirectToRoute('app_favorites');
    }

    #[Route('/favorite/{id}/delete', name: 'app_favorite_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(
        int $id,
        Request $request,
        FavoriteRepository $favorites,
        FavoriteManager $manager,
        TranslatorInterface $translator,
    ): Response {
        $favorite = $this->ownedFavorite($id, $favorites);

        if ($this->isCsrfTokenValid('favorite-delete-' . $id, (string) $request->request->get('_token'))) {
            $manager->delete($favorite);
            $this->addFlash('success', $translator->trans('favorite.removed'));
        }

        return $this->redirectToRoute('app_favorites');
    }

    private function ownedFavorite(int $id, FavoriteRepository $favorites): Favorite
    {
        $favorite = $favorites->find($id);
        if (!$favorite || $favorite->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException();
        }

        return $favorite;
    }
}
